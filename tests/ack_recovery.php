<?php
declare(strict_types=1);
require_once __DIR__.'/artifact_directory.php';
require dirname(__DIR__).'/bootstrap.php';
require __DIR__.'/fixtures/OperationsRuntimeFixture.php';
use RadioStamp\Core\{Database,FrozenClock};
use RadioStamp\Discord\{HttpAdapter,HttpRequest,HttpResponse,DiscordRestClient};
use RadioStamp\Http\{OperationsEndpoint,PostResponseDriver,Response,InteractionQueue};
use RadioStamp\Operations\{AckHandoff,OperationsConfig,OperationsRuntime};
use RadioStamp\Security\TokenCipher;

final class AckRecoveryHttp implements HttpAdapter
{
    public bool $identityFails=false;
    public function __construct(public RuntimeFixtureHttp $inner){}
    public function exchange(HttpRequest $request):HttpResponse
    {
        if($this->identityFails&&parse_url($request->url(),PHP_URL_PATH)==='/api/v10/users/@me'){return new HttpResponse(403,[],'{}');}
        return $this->inner->exchange($request);
    }
}
final class AckRecoveryDriver implements PostResponseDriver
{
    public int $finishes=0;
    public function __construct(private ?PDO $locker=null,private bool $success=true,private bool $throw=false){}
    public function available():bool{return true;}
    public function finish():bool{$this->finishes++;if($this->throw){throw new RuntimeException('fictional_finish_failure');}if($this->locker!==null){$this->locker->exec('BEGIN IMMEDIATE');}return $this->success;}
}

// Child paths only use fake adapters, or queue/journal storage without any adapter.
if(isset($argv[1])&&in_array($argv[1],['--import','--cycle'],true)){
    $configPath=$argv[2]??'';$config=OperationsConfig::load($configPath,dirname(dirname($configPath)).'/public');$at=(int)($argv[3]??0);
    if($argv[1]==='--import'){
        $db=new Database($config['private_db_path'],200);$queue=new InteractionQueue($db->pdo(),new TokenCipher($config['token_encryption_key_hex']));$queue->initializeAcknowledgements();
        echo json_encode((new AckHandoff($config))->recover($queue,$at),JSON_THROW_ON_ERROR)."\n";exit(0);
    }
    $clock=new FrozenClock((new DateTimeImmutable('@'.$at))->setTimezone(new DateTimeZone('Asia/Tokyo')));
    $inner=new RuntimeFixtureHttp();$inner->voices=['900000000000000102'=>DiscordRestClient::VOICE_CHANNEL_ID];$http=new AckRecoveryHttp($inner);
    $runtime=new OperationsRuntime($http,fn()=>$inner->transport(),$clock);$context=$runtime->build($config,static fn()=>true);
    $cycle=$runtime->cycle($context,static fn()=>true);$runtime->cycle($context,static fn()=>true);
    $rolePuts=count(array_filter($inner->calls,static fn(HttpRequest $r):bool=>$r->method()==='PUT'&&preg_match('~/members/[0-9]+/roles/[0-9]+$~',parse_url($r->url(),PHP_URL_PATH))===1));
    $achievementPosts=count(array_filter($inner->messages,static fn(array $m):bool=>$m['channel_id']===DiscordRestClient::RECORDS_CHANNEL_ID));
    echo json_encode(['cycle'=>$cycle,'callbacks'=>count($inner->callbacks),'messages'=>count($inner->messages),'role_puts'=>$rolePuts,'achievement_posts'=>$achievementPosts,'attendance'=>(int)$context->db->pdo()->query('SELECT COUNT(*) FROM attendance')->fetchColumn()],JSON_THROW_ON_ERROR)."\n";
    $context->roster->close();exit(0);
}

$checks=0;$scenarios=[];$sequence=0;$fixtures=[];
$assert=static function(bool $ok,string $why)use(&$checks):void{if(!$ok){throw new RuntimeException('ACK recovery: '.$why);}$checks++;};
$same=static function(mixed $expected,mixed $actual,string $why)use($assert):void{$assert($expected===$actual,$why.' expected='.json_encode($expected).' actual='.json_encode($actual));};
$temp=str_replace('\\','/',sys_get_temp_dir().'/radio-ack-recovery-'.bin2hex(random_bytes(8)));mkdir($temp,0700);
$make=static function(string $name)use($temp,&$fixtures):array{
    $root=$temp.'/'.$name;mkdir($root,0700);mkdir($root.'/private',0700);mkdir($root.'/public',0700);
    $config=runtimeFixtureConfig($root,'best_effort',true);$config['participant_scope']='guild';$config['participant_user_ids']=[];
    $config['live_automation_enabled']=true;$config['diagnostics_enabled']=true;
    $config['schedule']=['daily_times'=>[],'one_off_times'=>[['at'=>'2026-10-20 14:30','slot_id'=>'morning']]];
    $path=$root.'/private/config.php';file_put_contents($path,"<?php\nreturn ".var_export($config,true).";\n");
    $config=OperationsConfig::load($path,$root.'/public');
    $clock=new FrozenClock('2026-10-20T14:05:00+09:00');$inner=new RuntimeFixtureHttp();$inner->voices=['900000000000000102'=>DiscordRestClient::VOICE_CHANNEL_ID];
    $http=new AckRecoveryHttp($inner);$runtime=new OperationsRuntime($http,fn()=>$inner->transport(),$clock);$context=$runtime->build($config,static fn()=>true);
    $fixture=compact('root','config','path','clock','inner','http','runtime','context');$fixtures[]=$fixture;return $fixture;
};
$signed=static function(array $fixture,string $action='radio-status',?string $id=null)use(&$sequence):array{
    $id??=(string)(900000000000700000+ ++$sequence);
    $data=['id'=>$id,'application_id'=>DiscordRestClient::APPLICATION_ID,'guild_id'=>DiscordRestClient::GUILD_ID,
        'channel_id'=>DiscordRestClient::VOICE_CHANNEL_ID,'type'=>$action==='attend_button'?3:2,'token'=>'fictional_ack_callback_token_canary',
        'member'=>['user'=>['id'=>'900000000000000102','username'=>'fictional_ack_name_canary']],
        'data'=>$action==='attend_button'?['component_type'=>2,'custom_id'=>'radio:attend:2026-10-20_1430']:['type'=>1,'name'=>$action]];
    $body=json_encode($data,JSON_THROW_ON_ERROR);$at=(string)$fixture['clock']->now()->getTimestamp();$keys=sodium_crypto_sign_seed_keypair(str_repeat("\x17",32));
    return [['REQUEST_METHOD'=>'POST','HTTP_X_SIGNATURE_TIMESTAMP'=>$at,'HTTP_X_SIGNATURE_ED25519'=>bin2hex(sodium_crypto_sign_detached($at.$body,sodium_crypto_sign_secretkey($keys)))],$body,$id];
};
$prepare=static function(array $f,AckRecoveryDriver $driver,string $action='radio-status',?string $id=null)use($signed):array{
    [$server,$body,$id]=$signed($f,$action,$id);$delivery=(new OperationsEndpoint($f['root'].'/public',$driver,$f['runtime']))->prepare($server,$body,$f['path']);return [$delivery,$id];
};
$markers=static fn(array $f):array=>glob($f['root'].'/private/ack-handoff/*.ack.json')?:[];
$state=static function(array $f,string $id):array{$q=$f['context']->db->pdo()->prepare('SELECT state,attempts,result_code,length(token_ciphertext) AS token_length FROM interaction_jobs WHERE interaction_id=?');$q->execute([$id]);return $q->fetch(PDO::FETCH_ASSOC)?:[];};
$acks=static fn(array $f):int=>(int)$f['context']->db->pdo()->query('SELECT COUNT(*) FROM operations_ack_receipts')->fetchColumn();
$child=static function(string $mode,array $f):array{
    $command=[PHP_BINARY,'-c',php_ini_loaded_file(),__FILE__,$mode,$f['path'],(string)$f['clock']->now()->getTimestamp()];
    $process=proc_open($command,[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes);
    if(!is_resource($process)){throw new RuntimeException('fictional_child_unavailable');}fclose($pipes[0]);$out=stream_get_contents($pipes[1]);$error=stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);$exit=proc_close($process);
    if($exit!==0||$error!==''){throw new RuntimeException('fictional_child_failed');}return json_decode($out,true,32,JSON_THROW_ON_ERROR);
};
$ticket=static function(array $f,string $id):array{return $f['context']->handoff->prepare($f['context']->queue,$id);};
$emit=static fn(Response $response)=>null;

try{
    // Original defect: the writer stays locked throughout the HTTP post-response attempt.
    $f=$make('locked');$assert(OperationsConfig::ready($f['config']),'full-feature configuration ready');
    $same('best_effort',$f['config']['closure_policy'],'normal closure policy');$same('guild',$f['config']['participant_scope'],'normal guild scope');
    foreach(['operations_enabled','participant_commands_enabled','live_automation_enabled','posts_enabled','room_permissions_enabled','roles_enabled','achievement_notifications_enabled','member_actions_enabled','accept_voice_race_risk','periodic_worker_enabled']as$key){$same(true,$f['config'][$key],'full normal feature '.$key);}
    $locker=new PDO('sqlite:'.$f['config']['private_db_path']);$locker->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);
    $locker->exec('BEGIN IMMEDIATE');[$blocked,$blockedId]=$prepare($f,new AckRecoveryDriver());$locker->exec('ROLLBACK');
    $same(503,$blocked->response->status,'pre-ACK writer lock fails closed');$same([],$state($f,$blockedId),'pre-ACK failure did not enqueue');$same(0,count($markers($f)),'pre-ACK failure has no proof');
    $driver=new AckRecoveryDriver($locker);[$delivery,$id]=$prepare($f,$driver,'radio-attend');
    $same(['type'=>5,'data'=>['flags'=>64]],$delivery->response->body,'normal defer prepared');
    $same(true,$delivery->deliver($driver,$emit),'post-response failure is durably handed off');
    $same(1,$driver->finishes,'formal finish was called');$same(1,count($markers($f)),'DB lock cannot prevent journal save');
    $same(0,$acks($f),'DB receipt not falsely claimed during lock');$same('pending',$state($f,$id)['state'],'locked job stays pending');
    $same(0,count($f['inner']->calls),'no role, post or member HTTP ran before durable handoff');
    $record=file_get_contents($markers($f)[0]);
    foreach(['fictional_ack_callback_token_canary','fictional_ack_name_canary',$f['config']['bot_token'],$f['config']['token_encryption_key_hex']]as$canary){$assert(!str_contains($record,$canary),'journal contains no secret or display name');}
    if(PHP_OS_FAMILY!=='Windows'){$same(0600,fileperms($markers($f)[0])&0777,'proof mode600');$same(0700,fileperms(dirname($markers($f)[0]))&0777,'journal directory700');}
    $locker->exec('ROLLBACK');$locker=null;$f['clock']->advance(1);
    $restart=$child('--cycle',$f);$same(1,$restart['cycle']['jobs_processed'],'fresh process recovers confirmed ACK');$same(1,$restart['callbacks'],'fresh process sends follow-up once');
    $same('done',$state($f,$id)['state'],'recovered job done');$same(0,(int)$state($f,$id)['token_length'],'finished job loses token');$same(1,$acks($f),'one committed ACK receipt');$same(0,count($markers($f)),'committed proof cleaned');
    $before=count($f['inner']->calls);[$replay,$replayId]=$prepare($f,new AckRecoveryDriver(),'radio-attend',$id);$same($id,$replayId,'same interaction ID replay');
    $same(true,$replay->deliver(new AckRecoveryDriver(),$emit),'same-ID re-delivery safe');$same($before,count($f['inner']->calls),'same-ID replay causes no extra external effect');
    $same(1,$acks($f),'same-ID replay does not add receipt');
    $scenarios['locked_http_then_process_restart']=['recovered'=>true,'followups'=>1,'duplicate_effects'=>0];

    // The same durable path recovers attendance; normal periodic effects retain once-only ledgers.
    $attendLocker=new PDO('sqlite:'.$f['config']['private_db_path']);$attendDriver=new AckRecoveryDriver($attendLocker);
    [$attendDelivery,$attendId]=$prepare($f,$attendDriver,'attend_button');$same(true,$attendDelivery->deliver($attendDriver,$emit),'attendance ACK handed off during lock');
    $same(1,count($markers($f)),'attendance finish proof saved');$attendLocker->exec('ROLLBACK');
    $attendanceRestart=$child('--cycle',$f);$same(1,$attendanceRestart['attendance'],'restarted process records one attendance');
    $same(1,$attendanceRestart['role_puts'],'normal role effect is applied once');$same(1,$attendanceRestart['achievement_posts'],'normal title notice posts once');
    [$attendReplay,$replayedId]=$prepare($f,new AckRecoveryDriver(),'attend_button',$attendId);$same($attendId,$replayedId,'attendance replay same ID');
    $beforeCalls=count($f['inner']->calls);$same(true,$attendReplay->deliver(new AckRecoveryDriver(),$emit),'attendance replay safely responds');
    $same($beforeCalls,count($f['inner']->calls),'attendance replay emits no extra operation');
    $same(1,(int)$f['context']->db->pdo()->query('SELECT COUNT(*) FROM attendance')->fetchColumn(),'replay cannot add attendance');
    $same(1,(int)$f['context']->db->pdo()->query('SELECT COUNT(*) FROM achievement_notices')->fetchColumn(),'single title notice ledger');
    $scenarios['attendance_restart_and_replay']=['attendance'=>1,'role_puts'=>1,'achievement_posts'=>1,'duplicate_effects'=>0];

    foreach(['finish_false','finish_throw']as$case){
        $x=$make($case);$d=new AckRecoveryDriver(null,$case!=='finish_false',$case==='finish_throw');[$response,$job]=$prepare($x,$d);
        $same(false,$response->deliver($d,$emit),$case.' does not confirm');$same(0,count($markers($x)),$case.' has no journal');
        $x['runtime']->cycle($x['context'],static fn()=>true);$same(0,$acks($x),$case.' has no ACK receipt');$same('pending',$state($x,$job)['state'],$case.' stays unacknowledged');$same(0,count($x['inner']->callbacks),$case.' never executes job');
        if($case==='finish_false'){
            $original=$ticket($x,$job);$x['clock']->advance(60);[$sameRequest,$sameId]=$prepare($x,new AckRecoveryDriver(),'radio-status',$job);
            $same($job,$sameId,'unconfirmed same-ID re-delivery');$same($original['expires_at'],$ticket($x,$job)['expires_at'],'same-ID re-delivery never extends original token expiry');
            [$conflict,$sameId]=$prepare($x,new AckRecoveryDriver(),'radio-card',$job);$same(409,$conflict->response->status,'same ID with different signed action conflicts');$same(0,count($markers($x)),'conflict cannot mint finish proof');
        }
    }

    // Authenticate immutable queue fields, instance generation, original expiry, and storage location.
    foreach(['mac','request_hash','job_target','scope','instance_generation','oversized','partial']as$case){
        $x=$make($case);[$notDelivered,$job]=$prepare($x,new AckRecoveryDriver());$proof=$ticket($x,$job);$handoff=$x['context']->handoff;$now=$x['clock']->now()->getTimestamp();
        if($case==='partial'){
            $partial=$x['root'].'/private/ack-handoff/.tmp-'.str_repeat('a',32);file_put_contents($partial,'{"payload":');chmod($partial,0600);
        }else{
            $same(true,$handoff->recordFinished($proof,$now),$case.' setup finished proof');$path=$markers($x)[0];
            if($case==='mac'){$value=json_decode(file_get_contents($path),true);$value['mac']=str_repeat('0',64);file_put_contents($path,json_encode($value));}
            if($case==='request_hash'){$value=json_decode(file_get_contents($path),true);$value['payload']['request_hash']=str_repeat('f',64);file_put_contents($path,json_encode($value));}
            if($case==='oversized'){file_put_contents($path,str_repeat('x',5000));}
            if($case==='job_target'){$x['context']->db->pdo()->prepare('UPDATE interaction_jobs SET target=? WHERE interaction_id=?')->execute(['changed',$job]);}
            if($case==='scope'){$x['context']->db->pdo()->prepare('UPDATE interaction_jobs SET scope_mode=? WHERE interaction_id=?')->execute(['mock',$job]);}
            if($case==='instance_generation'){
                $x['context']->db->pdo()->prepare("UPDATE operations_queue_instance SET instance_id=? WHERE name='operations'")->execute([str_repeat('e',64)]);
                $freshQueue=new InteractionQueue($x['context']->db->pdo(),new TokenCipher($x['config']['token_encryption_key_hex']));$freshQueue->initializeAcknowledgements();
            }
        }
        $recovered=$handoff->recover($freshQueue??$x['context']->queue,$now);unset($freshQueue);
        $same(0,$acks($x),$case.' cannot authorize ACK');$same(0,count($x['inner']->callbacks),$case.' executes no job');
        if($case!=='partial'){$same(1,$recovered['rejected'],$case.' rejected explicitly');$same(0,count($markers($x)),$case.' bad record removed');}
        else{$same(0,$recovered['recovered'],'partial write is not a proof');touch($partial,$now-901);$handoff->recover($x['context']->queue,$now);$same(false,is_file($partial),'old temporary write safely cleaned');}
    }
    $x=$make('other_instance_source');[$notDelivered,$job]=$prepare($x,new AckRecoveryDriver());$proof=$ticket($x,$job);$x['context']->handoff->recordFinished($proof,$x['clock']->now()->getTimestamp());
    $y=$make('other_instance_destination');[$notDelivered,$sameJob]=$prepare($y,new AckRecoveryDriver(),'radio-status',$job);
    $copied=$y['root'].'/private/ack-handoff/'.basename($markers($x)[0]);copy($markers($x)[0],$copied);chmod($copied,0600);
    $same(1,$y['context']->handoff->recover($y['context']->queue,$y['clock']->now()->getTimestamp())['rejected'],'another DB/path cannot reuse proof');$same(0,$acks($y),'other instance receives no ACK');
    $same(false,$x['context']->handoff->recordFinished(array_replace($proof,['scope_mode'=>'mock']),$x['clock']->now()->getTimestamp()),'nonoperations proof cannot be recorded');
    $same(false,$x['context']->handoff->recordFinished($proof,$proof['expires_at']),'finish at expiry is not recorded');
    $same(false,$x['context']->handoff->recordFinished(array_replace($proof,['expires_at'=>$proof['expires_at']+1]),$x['clock']->now()->getTimestamp()),'proof expiry cannot extend the original lifetime');

    // Expiry is independent of ACK eligibility, live identity success, and a nonempty scheduler.
    $x=$make('idle_expiry');[$notDelivered,$job]=$prepare($x,new AckRecoveryDriver());$proof=$ticket($x,$job);$x['context']->handoff->recordFinished($proof,$x['clock']->now()->getTimestamp());
    [$unconfirmed,$unconfirmedId]=$prepare($x,new AckRecoveryDriver());$x['clock']->advance(900);$x['http']->identityFails=true;
    $cycle=$x['runtime']->cycle($x['context'],static fn()=>true);$same('operations_identity_unverified',$cycle['code'],'identity is deliberately failing');
    foreach([$job,$unconfirmedId]as$expiredId){$same('expired',$state($x,$expiredId)['state'],'idle expiry covers confirmed and unconfirmed job');$same(0,(int)$state($x,$expiredId)['token_length'],'expired token removed without identity');}
    $same(0,count($markers($x)),'expired proof cleaned');$same(0,$acks($x),'expired proof was not imported');$same(0,count($x['inner']->callbacks),'expired request never executes');

    // Two actual PHP processes import duplicate immutable proofs; commit/unlink is idempotent.
    $x=$make('parallel');[$notDelivered,$job]=$prepare($x,new AckRecoveryDriver());$proof=$ticket($x,$job);$now=$x['clock']->now()->getTimestamp();
    $same(true,$x['context']->handoff->recordFinished($proof,$now),'first duplicate proof');$same(true,$x['context']->handoff->recordFinished($proof,$now),'second duplicate proof');
    $processes=[];
    for($i=0;$i<2;$i++){
        $command=[PHP_BINARY,'-c',php_ini_loaded_file(),__FILE__,'--import',$x['path'],(string)$now];$process=proc_open($command,[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes);
        if(!is_resource($process)){throw new RuntimeException('parallel_child_unavailable');}fclose($pipes[0]);$processes[]=[$process,$pipes];
    }
    foreach($processes as[$process,$pipes]){$output=stream_get_contents($pipes[1]);$errors=stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);$same(0,proc_close($process),'parallel importer exit');$same('',$errors,'parallel importer no warning');$assert(is_array(json_decode($output,true)),'parallel importer safe result');}
    $same(1,$acks($x),'parallel import only one ACK receipt');$same(0,count($markers($x)),'parallel import cleaned both proofs');
    $restart=$child('--cycle',$x);$same(1,$restart['callbacks'],'two proofs produce one followup');$same('done',$state($x,$job)['state'],'parallel import job completes once');
    $scenarios['parallel_import']=['processes'=>2,'proofs'=>2,'ack_receipts'=>1,'followups'=>1];

    // Public root is trusted loader provenance, never a configuration-supplied override.
    $x=$make('public_guard');$bad=$x['config'];$bad['private_root']=$x['root'].'/public';$bad['private_db_path']=$x['root'].'/public/db.sqlite';$db=new Database($bad['private_db_path'],200);$bad['_validated_public_root']=$x['root'].'/public';
    $threw=false;try{new AckHandoff($bad);}catch(RuntimeException|InvalidArgumentException){$threw=true;}$same(true,$threw,'journal under public root rejected');unset($db);
    $loaded=OperationsConfig::load($x['path'],$x['root'].'/public');$same(realpath($x['root'].'/public'),$loaded['_validated_public_root'],'loader always sets actual public root');
    $x=$make('journal_unavailable');[$fsFailure,$fsJob]=$prepare($x,new AckRecoveryDriver());
    $journalPath=$x['root'].'/private/ack-handoff';rename($journalPath,$journalPath.'-held');file_put_contents($journalPath,'fictional_non_directory');
    $same(false,$fsFailure->deliver(new AckRecoveryDriver(),$emit),'filesystem failure after finish does not fall back to business work');
    $same(0,$acks($x),'filesystem failure mints no ACK receipt');$same(0,count($x['inner']->calls),'filesystem failure executes no HTTP');
    unlink($journalPath);rename($journalPath.'-held',$journalPath);
    $symlinkAvailable=false;$outside=$temp.'/symlink-sentinel';file_put_contents($outside,'sentinel');
    $link=$x['root'].'/private/ack-handoff/'.str_repeat('b',32).'.ack.json';
    if(function_exists('symlink')&&@symlink($outside,$link)){
        $symlinkAvailable=true;$same(1,$x['context']->handoff->recover($x['context']->queue,$x['clock']->now()->getTimestamp())['rejected'],'symlink record rejected');$same('sentinel',file_get_contents($outside),'symlink target neither read as proof nor modified');
        $linkedRoot=$temp.'/linked-private';$assert(@symlink($x['root'].'/private',$linkedRoot),'symlink directory fixture');$bad=$x['config'];$bad['private_root']=$linkedRoot;
        $threw=false;try{new AckHandoff($bad);}catch(RuntimeException){$threw=true;}$same(true,$threw,'symlink parent rejected');unlink($linkedRoot);
    }
    $scenarios['boundary_guards']=['tamper'=>true,'different_instance'=>true,'generation_change'=>true,'partial_write'=>true,'expiry_without_identity'=>true,'symlink_test_available'=>$symlinkAvailable,'posix_modes_enforced'=>PHP_OS_FAMILY!=='Windows'];
    $result=['status'=>'ACK_RECOVERY_LOCAL_VERIFIED_NOT_DEPLOYED','assertions'=>$checks,'real_network_calls'=>0,'server_commands'=>0,'deployed'=>false,
        'full_operational_flags'=>true,'closure_policy'=>'best_effort','participant_scope'=>'guild','command_registration_enabled'=>false,'scenarios'=>$scenarios,
        'limits'=>['finish_to_first_durable_write_crash_window'=>true,'directory_power_loss_guarantee'=>false,'windows_posix_mode_equivalence'=>false]];
    $destination=getenv('RADIO_STAMP_TEST_ARTIFACT_DIR');if(!is_dir($destination)){mkdir($destination,0700,true);}
    file_put_contents($destination.'/ack-recovery.safe.json',json_encode($result,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR)."\n");
    echo "ACK recovery: $checks assertions passed (durable finish proof, restart, independent expiry, tamper and parallel import; fake only).\n";
}finally{
    foreach($fixtures as$fixture){$fixture['context']->roster->close();}
    $fixtures=[];
    unset($make,$fixtures,$fixture,$f,$x,$y,$context,$runtime,$locker,$driver,$delivery,$blocked,$replay,$notDelivered,$unconfirmed,$handoff,$response,$freshQueue,$db,$attendLocker,$attendDriver,$attendDelivery,$attendReplay,$sameRequest,$conflict,$fsFailure);
    gc_collect_cycles();$resolved=realpath($temp);$allowed=realpath(sys_get_temp_dir());
    if($resolved===false||$allowed===false||!str_starts_with(str_replace('\\','/',$resolved),str_replace('\\','/',$allowed).'/radio-ack-recovery-')){throw new RuntimeException('cleanup_boundary');}
    $iterator=new RecursiveIteratorIterator(new RecursiveDirectoryIterator($temp,FilesystemIterator::SKIP_DOTS),RecursiveIteratorIterator::CHILD_FIRST);
    foreach($iterator as$item){if($item->isLink()||$item->isFile()){unlink($item->getPathname());}else{rmdir($item->getPathname());}}rmdir($temp);
}
