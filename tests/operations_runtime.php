<?php
declare(strict_types=1);
require_once __DIR__.'/artifact_directory.php';
require dirname(__DIR__).'/bootstrap.php';
require __DIR__.'/fixtures/OperationsRuntimeFixture.php';
use RadioStamp\Core\{FrozenClock,Database,TitlePolicy};
use RadioStamp\Operations\{OperationsRuntime,OperationsCli,OperationsConfig};
use RadioStamp\Discord\{DiscordRestClient,ServerOperationsClient,HttpRequest,Gateway};
use RadioStamp\Http\{OperationsEndpoint,PostResponseDriver,Response};

$checks=0;
$same=static function(mixed $expected,mixed $actual,string $why)use(&$checks):void{++$checks;if($expected!==$actual){throw new RuntimeException('Operations runtime: '.$why.' expected='.json_encode($expected).' actual='.json_encode($actual));}};
final class RuntimeDriver implements PostResponseDriver {
    public int $finished=0;
    public function __construct(public bool $supported=true,public bool $success=true){}
    public function available():bool{return $this->supported;}
    public function finish():bool{$this->finished++;return $this->success;}
}
$root=sys_get_temp_dir().'/radio-runtime-'.bin2hex(random_bytes(8));mkdir($root,0700);$fixtures=[];
$make=static function(string $name,string $policy='manual',bool $roles=false)use($root,&$fixtures):array{
    $folder=$root.'/'.$name;mkdir($folder,0700);mkdir($folder.'/private',0700);mkdir($folder.'/public',0700);
    $config=runtimeFixtureConfig($folder,$policy,$roles);$path=$folder.'/private/config.php';file_put_contents($path,"<?php\nreturn ".var_export($config,true).";\n");
    $clock=new FrozenClock('2026-10-19T07:08:00+09:00');$http=new RuntimeFixtureHttp();
    $runtime=new OperationsRuntime($http,fn()=>$http->transport(),$clock);$context=$runtime->build($config,static fn()=>true);
    $value=compact('folder','config','path','clock','http','runtime','context');$fixtures[]=$value;return $value;
};
$owner='800000000000001021';$other='900000000000000102';$session='2026-10-19_0700';$sequence=0;
$signed=static function(array $f,string $user,string $action,array $override=[])use(&$sequence):array{
    $data=['id'=>(string)(900000000000002000+ ++$sequence),'application_id'=>DiscordRestClient::APPLICATION_ID,'guild_id'=>DiscordRestClient::GUILD_ID,
        'channel_id'=>DiscordRestClient::VOICE_CHANNEL_ID,'token'=>'fictional_runtime_callback_000000','type'=>$action==='button'?3:2,
        'member'=>['nick'=>'同名 @everyone','user'=>['id'=>$user,'username'=>'fictional']],
        'data'=>$action==='button'?['component_type'=>2,'custom_id'=>'radio:attend:2026-10-19_0700']:['type'=>1,'name'=>$action]];
    $data=array_replace_recursive($data,$override);$body=json_encode($data,JSON_THROW_ON_ERROR);$at=(string)$f['clock']->now()->getTimestamp();
    $keys=sodium_crypto_sign_seed_keypair(str_repeat("\x17",32));
    return [['REQUEST_METHOD'=>'POST','HTTP_X_SIGNATURE_TIMESTAMP'=>$at,'HTTP_X_SIGNATURE_ED25519'=>bin2hex(sodium_crypto_sign_detached($at.$body,sodium_crypto_sign_secretkey($keys)))],$body,$data['id']];
};
$row=static function(array $f)use($session):array{$q=$f['context']->db->pdo()->prepare('SELECT * FROM sessions WHERE id=?');$q->execute([$session]);return $q->fetch()?:[];};
$receipts=static fn(array $f):array=>array_values(array_filter($f['http']->messages,static fn(array $m):bool=>($m['components']??[])!==[]));
$send=static function(array $f,string $user,string $action,array $override=[])use($signed):array{
    [$server,$body,$id]=$signed($f,$user,$action,$override);$driver=new RuntimeDriver();$endpoint=new OperationsEndpoint($f['folder'].'/public',$driver,$f['runtime']);
    $delivery=$endpoint->prepare($server,$body,$f['path']);$ok=$delivery->deliver($driver,static function(Response $response):void{});return [$delivery->response,$ok,$id];
};

// Real signed ingress, ACK completion, encrypted queue, worker, HTTP adapter, and private follow-up.
$f=$make('signed','manual',true);$same(0,count($f['http']->calls),'construction sends no HTTP');$same(0,$f['http']->connections,'construction opens no socket');
$same(true,OperationsConfig::ready(OperationsConfig::load($f['path'],$f['folder'].'/public')),'private configuration loads');
$f['http']->voices=[$owner=>DiscordRestClient::VOICE_CHANNEL_ID,$other=>DiscordRestClient::VOICE_CHANNEL_ID];
[$server,$body,$id]=$signed($f,$owner,'radio-attend');$badDriver=new RuntimeDriver(true,false);$endpoint=new OperationsEndpoint($f['folder'].'/public',$badDriver,$f['runtime']);
$delivery=$endpoint->prepare($server,$body,$f['path']);$same(['type'=>5,'data'=>['flags'=>64]],$delivery->response->body,'private defer');$same(0,count($f['http']->calls),'prepare is network free');
$same(false,$delivery->deliver($badDriver,static function(Response $r):void{}),'failed finish stops worker');
$same(0,count($receipts($f)),'no posting after failed ACK');
$f['runtime']->cycle($f['context'],static fn()=>true);$same(0,count($receipts($f)),'CLI cannot consume unacknowledged job');
$same(0,(int)$f['context']->db->pdo()->query('SELECT COUNT(*) FROM operations_ack_receipts')->fetchColumn(),'no false ACK receipt');
[$server,$body]=$signed($f,$owner,'radio-attend');$good=new RuntimeDriver();$delivery=(new OperationsEndpoint($f['folder'].'/public',$good,$f['runtime']))->prepare($server,$body,$f['path']);
$before=count($f['http']->calls);$same(true,$delivery->deliver($good,static function(Response $r):void{}),'ACK then operation completes');$same(false,$delivery->deliver($good,static function(Response $r):void{}),'delivery one use');
$same(true,count($f['http']->calls)>$before,'network begins after ACK');$same(1,$good->finished,'one finish');$same(1,count($receipts($f)),'one shared receipt');
$origin=$f['clock']->now()->getTimestamp();$same($origin+600,(int)$row($f)['closes_at'],'exact first-ingress ten-minute cutoff');
$same(['参加','カード表示'],array_column($receipts($f)[0]['components'][0]['components'],'label'),'shared buttons');$same(Gateway::ALLOWED_MENTIONS,$receipts($f)[0]['allowed_mentions'],'no ordinary mention');
$same(0,(int)$f['context']->db->pdo()->query('SELECT COUNT(*) FROM attendance')->fetchColumn(),'opening command does not register caller');
$f['clock']->advance(5);$same(true,$send($f,$other,'radio-attend')[1],'other allowlisted caller reuses receipt');$same($origin+600,(int)$row($f)['closes_at'],'duplicate deadline unchanged');$same(1,count($receipts($f)),'no repeat shared posting');
foreach([$owner,$other]as$user){$same(true,$send($f,$user,'button')[1],'individual attendance delivered');$same(1,$f['context']->service->statusData($user)['total_count'],'individual count');$same(1,$f['context']->service->statusData($user)['total_days'],'actual JST day');$f['runtime']->cycle($f['context'],static fn()=>true);$same([$f['config']['role_ids'][1]],$f['http']->roles[$user],'only current title after periodic sync');}
$same(true,$send($f,$owner,'button')[1],'repeat click responds');$same(2,(int)$f['context']->db->pdo()->query('SELECT COUNT(*) FROM attendance')->fetchColumn(),'repeat does not increase attendance');
$achievements=array_values(array_filter($f['http']->messages,static fn(array $m):bool=>$m['channel_id']===DiscordRestClient::RECORDS_CHANNEL_ID));$same(2,count($achievements),'one achievement per user');$same([$owner],$achievements[0]['allowed_mentions']['users'],'actual target mention only');
$before=count($f['http']->calls);$same(true,$send($f,$owner,'radio-status',['channel_id'=>DiscordRestClient::TEXT_CHANNEL_ID])[1],'self status outside VC');
$voiceCalls=array_filter(array_slice($f['http']->calls,$before),static fn(HttpRequest $r):bool=>str_contains($r->url(),'/voice-states/'));$same(0,count($voiceCalls),'status does not query voice');
$same(true,$send($f,$owner,'radio-card',['channel_id'=>DiscordRestClient::TEXT_CHANNEL_ID])[1],'private card completes');$same(true,$f['http']->callbacks[array_key_last($f['http']->callbacks)]['png'],'private PNG reaches fake original callback');
[$server,$body]=$signed($f,'900000000000000199','radio-attend');$same(403,(new OperationsEndpoint($f['folder'].'/public',new RuntimeDriver(),$f['runtime']))->prepare($server,$body,$f['path'])->response->status,'outsider rejected at ingress');
$f['clock']->set('2026-10-19T07:17:00+09:00');$f['runtime']->cycle($f['context'],static fn()=>true);$same($origin+540,(int)$row($f)['warning_sent_at'],'nine-minute warning');$same('started',$row($f)['status'],'warning keeps receipt active');
$f['clock']->advance(60);$f['runtime']->cycle($f['context'],static fn()=>true);$same('closed',$row($f)['status'],'manual policy closes gate');$same(0,count($f['http']->memberPatches()),'manual policy never moves a member');$same(0,$f['http']->connections,'manual policy needs no Gateway');
$same(1<<20,(int)$f['http']->deny&(1<<20),'CONNECT denied after close');$same(1024,(int)$f['http']->allow&~(1<<20),'unrelated room permission preserved');

// End-to-end best-effort closure including the non-clicking occupant; wholly fictional transport.
$b=$make('best','best_effort');$b['http']->voices=[$owner=>DiscordRestClient::VOICE_CHANNEL_ID,$other=>DiscordRestClient::VOICE_CHANNEL_ID];
$same('receipt_opened',$b['context']->service->openAttendance($owner,'best-open')['code'],'best flow opens');$same('attended',$b['context']->service->attend($session,$owner,'best-attend')['code'],'registered owner');
$b['clock']->advance(540);$b['runtime']->cycle($b['context'],static fn()=>true);$same(0,$b['http']->connections,'no Gateway before closure');
$b['clock']->advance(60);$cycle=$b['runtime']->cycle($b['context'],static fn()=>true);$same(1,$b['http']->connections,'one bounded Gateway session at close');
$same('best_effort',$cycle['closure_policy'],'safe report names policy');$same(false,$cycle['atomic_member_updates'],'safe report never claims atomic move');$same(true,$cycle['residual_voice_race'],'safe report retains residual risk');
$same('closed',$row($b)['status'],'complete roster closes');$same(null,$b['http']->voices[$owner],'registered occupant disconnected');$same(null,$b['http']->voices[$other],'non-clicking occupant disconnected');$same(2,count($b['http']->memberPatches()),'each occupant once');
$same([null,null],array_map(static fn(HttpRequest $r):mixed=>json_decode($r->body(),true,32,JSON_THROW_ON_ERROR)['channel_id'],$b['http']->memberPatches()),'every closure PATCH is disconnect only');
$warnings=array_filter($b['http']->messages,static fn(array $m):bool=>str_contains($m['content'],'閉場まであと1分'));
$same(true,str_contains(array_values($warnings)[0]['content'],'自分で移動してください'),'warning requests voluntary relocation');
$same(true,str_contains(array_values($warnings)[0]['content'],'参加登録の有無にかかわらず'),'warning explains uniform disconnect policy');
$same(false,in_array(4,array_column($b['http']->gateway->sent,'op'),true),'never sends Bot voice join opcode');$b['context']->roster->close();$same(true,$b['http']->gateway->closed,'session closes explicitly');
foreach(['outsider','sequence','before','after']as$case){
    $x=$make($case,'best_effort');$x['http']->voices=[$owner=>DiscordRestClient::VOICE_CHANNEL_ID];$x['context']->service->openAttendance($owner,$case.'-open');$x['context']->service->attend($session,$owner,$case.'-attend');
    $x['clock']->advance(540);$x['runtime']->cycle($x['context'],static fn()=>true);$x['clock']->advance(60);
    if($case==='outsider'){$x['http']->voices['900000000000000199']=DiscordRestClient::VOICE_CHANNEL_ID;}
    if($case==='sequence'){$x['http']->sequenceGap=true;}
    if($case==='before'){$x['http']->raceBeforeGuard=true;}
    if($case==='after'){$x['http']->raceAfterGuard=true;}
    $x['runtime']->cycle($x['context'],static fn()=>true);
    if($case==='after'){$same(true,$x['http']->wrongSourceApplied,'final guard -> external move -> PATCH residual is demonstrable');$same(1,count($x['http']->memberPatches()),'residual case is explicitly best effort');}
    else{$same(0,count($x['http']->memberPatches()),$case.' observation stops member mutations');}
    if(in_array($case,['outsider','sequence'],true)){$same('closing',$row($x)['status'],$case.' retains pending closure');}
    $x['context']->roster->close();
}

// CLI uses only injected fake adapters, and storage actions do not replace the active DB.
$c=$make('cli');$cli=new OperationsCli($c['runtime']);$out='';$emit=static function(string $line)use(&$out):void{$out.=$line;};$args=['--config='.$c['path'],'--public-root='.$c['folder'].'/public'];
$same(0,$cli->run([...$args,'--dry-run'],$emit),'configuration dry run');$same(0,count($c['http']->calls),'dry run sends nothing');$out='';$same(1,$cli->run([...$args,'--register'],$emit),'registration disabled');$same(0,count($c['http']->calls),'disabled registration sends nothing');
$c['config']['command_registration_enabled']=true;file_put_contents($c['path'],"<?php\nreturn ".var_export($c['config'],true).";\n");$out='';$same(0,$cli->run([...$args,'--register'],$emit),'explicit fake registration');$same(3,count($c['http']->commands),'three commands');$same(['0'],array_values(array_unique(array_column($c['http']->commands,'default_member_permissions'))),'commands remain administrator restricted');
$out='';$same(0,$cli->run([...$args,'--register'],$emit),'repeat registration reconciles');$same(3,count($c['http']->commands),'no command duplicates');
$c['http']->commands[0]['options']=array_map(static function(array $option):array{unset($option['required']);return array_reverse($option,true);},$c['http']->commands[0]['options']);
$out='';$same(0,$cli->run([...$args,'--register'],$emit),'semantic option defaults and object key order reconcile');
$c['http']->commands[0]['description']='conflicting definition';$out='';$same(1,$cli->run([...$args,'--register'],$emit),'conflicting command stops');$same(3,count($c['http']->commands),'no overwrite or new command on conflict');
$out='';$same(2,$cli->run([...$args,'--run','--duration=56'],$emit),'budget above 55 rejected');$same(2,$cli->run([...$args,'--run','--once'],$emit),'exactly one action');
$out='';$same(0,$cli->run([...$args,'--once'],$emit),'one bounded cycle');$c['clock']->set('2026-10-19T14:00:00+09:00');$out='';$same(0,$cli->run([...$args,'--run','--duration=1'],$emit),'idle bounded worker exits outside the new lunchtime opening');
$out='';$same(0,$cli->run([...$args,'--backup'],$emit),'consistent backup');$backup=json_decode($out,true,32,JSON_THROW_ON_ERROR);$same(true,$backup['consistent_snapshot'],'snapshot metadata');
$out='';$same(0,$cli->run([...$args,'--restore='.$backup['file']],$emit),'restore to separate file');$restore=json_decode($out,true,32,JSON_THROW_ON_ERROR);$same(false,$restore['active_database_replaced'],'no overwrite active DB');
$same(false,str_contains($out,$c['config']['bot_token']),'CLI never prints token');$same(false,str_contains($out,$c['config']['token_encryption_key_hex']),'CLI never prints encryption key');
$out='';$same(1,$cli->run(['--config='.$c['path'],'--public-root='.$c['folder'],'--dry-run'],$emit),'private config under public root refused');

// Fictional scope data only; no historical guild observation is bundled.
$actualMap=OperationsConfig::TITLE_ROLE_IDS;
$proof=['roles'=>array_map(static fn(int $threshold):array=>['count'=>$threshold,'name'=>TitlePolicy::TITLES[$threshold],'permissions'=>'0'],array_keys($actualMap))];
$same(array_keys(TitlePolicy::TITLES),array_keys($actualMap),'fictional role map covers exactly the agreed ten thresholds');
$same($actualMap,OperationsConfig::TITLE_ROLE_IDS,'configured scope pins the fictional ten role IDs');
$wrongRoles=$f['config'];$wrongRoles['role_ids'][1]='900000000000000999';$same(false,OperationsConfig::ready($wrongRoles),'even valid-looking unapproved role ID is rejected before runtime');
$t=$make('fictional-role-map');$roleClient=new ServerOperationsClient($t['context']->db,$t['http'],$t['config']['bot_token'],['enabled'=>true,'roles_enabled'=>true,'posts_enabled'=>true,'role_ids'=>$actualMap]);
$unrelated='900000000000000999';$t['http']->roles[$owner]=[$unrelated];
foreach($actualMap as$threshold=>$role){
    $observed=array_values(array_filter($proof['roles'],static fn(array $r):bool=>$r['count']===$threshold))[0];
    $same(TitlePolicy::TITLES[$threshold],$observed['name'],'fictional name matches cumulative-count policy');$same('0',$observed['permissions'],'fictional title carries no permissions');
    $same(true,$roleClient->grantTitle($owner,$threshold)->ok,'configured fictional role ID grants then reads back in fake adapter');
    $same([$role],array_values(array_intersect($t['http']->roles[$owner],array_values($actualMap))),'exactly current title retained after each promotion');
    $same(true,in_array($unrelated,$t['http']->roles[$owner],true),'unrelated roles preserved');
    $same(true,$roleClient->postAchievement($owner,$threshold,$threshold,'ignored-key')->ok,'notification after verified title');
    $same(true,$roleClient->postAchievement($owner,$threshold,$threshold,'other-key')->ok,'repeat notification reconciles');
}
$same(10,count($t['http']->messages),'ten promotions produce exactly ten notifications');
$same(0,count($t['http']->memberPatches()),'title changes never PATCH entire member or touch voice');
foreach($t['http']->messages as$message){$same([$owner],$message['allowed_mentions']['users'],'promotion mentions fictional participant only');$same([],$message['allowed_mentions']['parse'],'promotion cannot mention roles/everyone');}

// Isolated copy of the HTTP stub: fictional PHP-FPM marker/body only, no real endpoint or HTTP.
$entry=file_get_contents(dirname(__DIR__).'/public/operations-candidate.php');
$entry=str_replace("PHP_SAPI==='cli'","false",$entry);
$entry=str_replace("file_get_contents('php://input',false,null,0,65537)","getenv('FICTIONAL_RUNTIME_BODY')",$entry);
$entryPath=$c['folder'].'/public/fixture-entry.php';file_put_contents($entryPath,$entry);
$runner=$c['folder'].'/private/entry-runner.php';
$ping=json_encode(['id'=>'900000000000009001','application_id'=>DiscordRestClient::APPLICATION_ID,'type'=>1],JSON_THROW_ON_ERROR);
$at=(string)time();$keys=sodium_crypto_sign_seed_keypair(str_repeat("\x17",32));$signature=bin2hex(sodium_crypto_sign_detached($at.$ping,sodium_crypto_sign_secretkey($keys)));
$runnerSource='<?php $_SERVER='.var_export(['DOCUMENT_ROOT'=>$c['folder'].'/public','REQUEST_METHOD'=>'POST','HTTP_X_SIGNATURE_TIMESTAMP'=>$at,'HTTP_X_SIGNATURE_ED25519'=>$signature],true).'; require '.var_export($entryPath,true).';';
file_put_contents($runner,$runnerSource);
$entryRun=static function(string $release)use($runner,$c,$ping):array{
    $env=['RADIO_STAMP_OPERATIONS_RELEASE'=>$release,'RADIO_STAMP_OPERATIONS_CONFIG'=>$c['path'],'FICTIONAL_RUNTIME_BODY'=>$ping];
    $process=proc_open([PHP_BINARY,'-c',php_ini_loaded_file(),$runner],[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes,null,$env);
    fclose($pipes[0]);$output=stream_get_contents($pipes[1]);$error=stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);$code=proc_close($process);return [$output,$error,$code];
};
$goodEntry=$entryRun(dirname(__DIR__));$same(['type'=>1],json_decode($goodEntry[0],true),'private release outside web root serves signed PING');$same('', $goodEntry[1],'no entry warning or path leakage');
$badEntry=$entryRun($c['folder'].'/public');$same(['error'=>'operations_unavailable'],json_decode($badEntry[0],true),'public release rejected before include');$same('',$badEntry[1],'invalid release discloses no path');
foreach($fixtures as$fixture){$fixture['context']->roster->close();}
echo "Operations runtime: $checks assertions passed (signed HTTP -> ACK -> queue -> fake REST/Gateway, CLI and recovery; no real connection).\n";
