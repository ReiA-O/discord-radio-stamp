<?php
declare(strict_types=1);
namespace RadioStamp\Discord {
    // Test-only timing hook. The canonical client and its transactions are executed unchanged.
    function random_bytes(int $length):string{
        $hook=$GLOBALS['unsentRecoveryClaimHook']??null;
        if($hook instanceof \Closure){$GLOBALS['unsentRecoveryClaimHook']=null;$hook();}
        return \random_bytes($length);
    }
}
namespace {
require_once __DIR__.'/artifact_directory.php';
if(($argv[1]??null)==='--claim-locker'){
    $locker=new PDO('sqlite:'.$argv[2]);$locker->exec('BEGIN IMMEDIATE');file_put_contents($argv[3],'locked');
    usleep((int)$argv[4]*1000);$locker->exec('ROLLBACK');exit(0);
}
require dirname(__DIR__).'/bootstrap.php';
require __DIR__.'/fixtures/OperationsRuntimeFixture.php';
use RadioStamp\Core\{Database,FrozenClock,RadioService};
use RadioStamp\Discord\{HttpAdapter,HttpRequest,HttpResponse,DiscordRestClient,ServerOperationsClient,ServerOperationsGateway,MemberActionFence,TransportOutcome};
use RadioStamp\Operations\{OperationsRuntime,OperationsConfig,SafeDiagnostics};

final class RecoveryFakeHttp implements HttpAdapter
{
    public bool $permit=true,$denyAfterVoice=false,$slowClosure=false,$readbackLoss=false;
    public float $mono=0;public array $voiceReads=[],$postModes=[],$attempts=[];
    public ?Closure $afterVoice=null,$duringPost=null,$onGuard=null;
    public string $patchMode='ok';
    public function __construct(public RuntimeFixtureHttp $inner){}
    public function exchange(HttpRequest $request):HttpResponse
    {
        $path=parse_url($request->url(),PHP_URL_PATH);$method=$request->method();
        if($method==='GET'&&str_contains($path,'/voice-states/')){
            $user=basename($path);$this->voiceReads[$user]=($this->voiceReads[$user]??0)+1;
            if($this->slowClosure&&$this->voiceReads[$user]===3){usleep(300000);}
            if($this->afterVoice!==null){($this->afterVoice)($user,$this->voiceReads[$user]);}
            if($this->denyAfterVoice){$this->permit=false;}
            if($this->readbackLoss){return new HttpResponse(0,[],'','transport_timeout');}
        }
        if($method==='PATCH'&&preg_match('~/guilds/[0-9]+/members/[0-9]+$~D',$path)){
            $this->attempts['member']=($this->attempts['member']??0)+1;
            if($this->patchMode==='timeout'){return new HttpResponse(0,[],'','transport_timeout');}
            if($this->patchMode==='exception'){throw new RuntimeException('CANARY_SECRET_TRANSPORT');}
            if($this->patchMode==='readback_loss'){$response=$this->inner->exchange($request);$this->readbackLoss=true;return $response;}
        }
        if($method==='POST'&&str_ends_with($path,'/messages')){
            $payload=json_decode($request->body(),true,32,JSON_THROW_ON_ERROR);
            $kind=($payload['components']??[])!==[]?'receipt':(str_contains($path,DiscordRestClient::RECORDS_CHANNEL_ID)?'achievement':'ordinary');
            $this->attempts[$kind]=($this->attempts[$kind]??0)+1;
            if($this->duringPost!==null){($this->duringPost)($kind);}
            $mode=$this->postModes[$kind]??'ok';
            if($mode==='429'){return new HttpResponse(429,[], '{"retry_after":0.25,"global":false}');}
            if($mode==='timeout'){return new HttpResponse(0,[],'','transport_timeout');}
            if($mode==='exception'){throw new RuntimeException('CANARY_SECRET_TRANSPORT');}
            if($mode==='500'){return new HttpResponse(500,[], '{}');}
            if($mode==='invalid_success'){return new HttpResponse(200,[], '{"id":"bad"}');}
        }
        return $this->inner->exchange($request);
    }
}
if(($argv[1]??null)==='--post-child'){
    $childDb=new Database($argv[2]);$childHttp=new RecoveryFakeHttp(new RuntimeFixtureHttp());
    $childHttp->duringPost=static function()use($argv):void{file_put_contents($argv[3],"POST\n",FILE_APPEND|LOCK_EX);usleep(100000);};
    $childClient=new ServerOperationsClient($childDb,$childHttp,'fictional_unsent_token_000000',['enabled'=>true,'posts_enabled'=>true]);
    $childResult=$childClient->postText(DiscordRestClient::TEXT_CHANNEL_ID,'parallel ordinary','multiprocess-key');
    echo json_encode(['ok'=>$childResult->ok,'outcome'=>$childResult->outcome->value],JSON_THROW_ON_ERROR);exit(0);
}
final class UnsentCommitFailurePDO extends PDO
{
    public bool $failNextCommit=false;
    public function exec(string $statement):int|false{
        if($statement==='COMMIT'&&$this->failNextCommit){$this->failNextCommit=false;throw new PDOException('CANARY_SECRET_COMMIT');}
        return parent::exec($statement);
    }
}
function unsentClaimLockCases():array
{
    $checks=0;$cases=[];
    $same=static function(mixed $expected,mixed $actual,string $why)use(&$checks):void{++$checks;if($expected!==$actual){throw new RuntimeException('Claim lock recovery: '.$why.' expected='.json_encode($expected).' actual='.json_encode($actual));}};
    $root=sys_get_temp_dir().'/radio-unsent-claims-'.bin2hex(\random_bytes(6));mkdir($root,0700);
    $launch=static function(string $dbPath,int $holdMs)use($root):object{
        $signal=$root.'/signal-'.bin2hex(\random_bytes(4));$args=[PHP_BINARY];if(($ini=php_ini_loaded_file())!==false){array_push($args,'-c',$ini);}
        array_push($args,__FILE__,'--claim-locker',$dbPath,$signal,(string)$holdMs);$pipes=[];
        $process=proc_open($args,[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes,null,null,['bypass_shell'=>true]);
        if(!is_resource($process)){throw new RuntimeException('Claim locker not started');}fclose($pipes[0]);
        $until=microtime(true)+2;while(!is_file($signal)&&microtime(true)<$until){usleep(1000);}if(!is_file($signal)){throw new RuntimeException('Claim locker not ready');}
        return (object)['process'=>$process,'pipes'=>$pipes];
    };
    $join=static function(object $child)use($same):void{
        $out=stream_get_contents($child->pipes[1]);$err=stream_get_contents($child->pipes[2]);fclose($child->pipes[1]);fclose($child->pipes[2]);
        $same(0,proc_close($child->process),'locker exits normally');$same('',$out.$err,'locker has no output');
    };
    $mutations=static fn(RecoveryFakeHttp $http):int=>count(array_filter($http->inner->calls,static fn(HttpRequest $r):bool=>in_array($r->method(),['POST','PUT','PATCH','DELETE'],true)));
    $user='900000000000000101';$voice=DiscordRestClient::VOICE_CHANNEL_ID;
    // Every operation-level claim precedes its own transport, including the PUT role lease.
    foreach(['post','put','patch','register']as$entry){
        $path=$root.'/'.$entry.'.sqlite';$db=new Database($path,200);$http=new RecoveryFakeHttp(new RuntimeFixtureHttp());$http->inner->voices[$user]=$voice;
        $options=['enabled'=>true,'posts_enabled'=>true,'roles_enabled'=>true,'role_ids'=>OperationsConfig::TITLE_ROLE_IDS,'commands_enabled'=>true,'participant_scope'=>'guild','member_actions_enabled'=>true,'accept_voice_race_risk'=>true];
        $client=new ServerOperationsClient($db,$http,'fictional_unsent_token_000000',$options);$child=null;
        $run=static function(ServerOperationsClient $c,int $generation)use($entry,$user,$voice):\RadioStamp\Discord\RestResult{return match($entry){
            'post'=>$c->postText(DiscordRestClient::TEXT_CHANNEL_ID,'claim recovery','claim-post'),
            'put'=>$c->grantTitle($user,1),
            'patch'=>$c->tryRelocate($user,null,'claim-patch',new MemberActionFence(DiscordRestClient::GUILD_ID,$user,$voice,$generation,time())),
            'register'=>$c->registerParticipantCommands(),
        };};
        $GLOBALS['unsentRecoveryClaimHook']=static function():void{throw new RuntimeException('CANARY_SECRET_ENTROPY');};
        $entropy=$run($client,1);$same('operation_claim_unavailable',$entropy->code,$entry.' owner generation failure classified');$same(TransportOutcome::NotStarted,$entropy->outcome,$entry.' owner entropy failure before transport');$same(0,$mutations($http),$entry.' owner failure no mutation');
        $GLOBALS['unsentRecoveryClaimHook']=static function()use($launch,$path,&$child):void{$child=$launch($path,350);};
        $first=$run($client,1);$same('operation_claim_unavailable',$first->code,$entry.' returns typed claim error');$same(TransportOutcome::NotStarted,$first->outcome,$entry.' no mutation transport started');
        $same(0,$mutations($http),$entry.' no mutation on locked claim');$join($child);$same(0,(int)$db->pdo()->query('SELECT COUNT(*) FROM operation_receipts')->fetchColumn(),$entry.' lock failure creates no operation row');
        $restarted=new ServerOperationsClient($db,$http,'fictional_unsent_token_000000',$options);$retry=$run($restarted,2);$same(true,$retry->ok,$entry.' fresh retry recovers');
        $same($entry==='register'?3:1,$mutations($http),$entry.' bounded real fake mutations');
        $cases[$entry]=['first_outcome'=>$first->outcome->value,'mutations_before_recovery'=>0,'retry_ok'=>$retry->ok,'mutation_calls_after_recovery'=>$mutations($http)];
    }
    // The Core owner has already claimed: local completion can persist pending after the shorter lock.
    foreach(['receipt','achievement','long_receipt']as$kind){
        $folder=$root.'/'.$kind;mkdir($folder,0700);mkdir($folder.'/private',0700);$config=runtimeFixtureConfig($folder,'best_effort',true);
        $config['participant_scope']='guild';$config['participant_user_ids']=[];$config['live_automation_enabled']=true;$config['schedule']=['daily_times'=>[],'one_off_times'=>[['at'=>'2026-10-20 14:30','slot_id'=>'morning']]];
        $clock=new FrozenClock('2026-10-20T14:30:00+09:00');$http=new RecoveryFakeHttp(new RuntimeFixtureHttp());$runtime=new OperationsRuntime($http,fn()=>$http->inner->transport(),$clock);$context=$runtime->build($config,static fn()=>true);$pdo=$context->db->pdo();$at=$clock->now()->getTimestamp();
        if($kind==='achievement'){
            $pdo->prepare('INSERT INTO participants(user_id,total_count) VALUES(?,1)')->execute([$user]);$context->client->grantTitle($user,1);
            $pdo->prepare("INSERT INTO achievement_notices(user_id,threshold,total_count,status,created_at) VALUES(?,1,1,'pending',?)")->execute([$user,$at]);
            $flush=new ReflectionMethod(RadioService::class,'flushAchievementNotices');$invoke=static fn()=> $flush->invoke($context->service);$table='achievement_notices';
        }else{
            $pdo->prepare("INSERT INTO sessions(id,scheduled_at,count_date,opens_at,unstarted_closes_at,status,started_at,closes_at,slot_id,stamp) VALUES(?,?,?,?,?,'started',?,?,?,?)")->execute(['2026-10-20_1430',$at,'2026-10-20',$at-1800,$at+1800,$at,$at+600,'morning','LED']);
            $pdo->prepare("INSERT INTO receipt_announcements(session_id,status,created_at) VALUES(?,'pending',?)")->execute(['2026-10-20_1430',$at]);
            $flush=new ReflectionMethod(RadioService::class,'flushReceiptAnnouncement');$invoke=static fn()=> $flush->invoke($context->service,'2026-10-20_1430');$table='receipt_announcements';
        }
        $child=null;$hold=$kind==='long_receipt'?900:350;$GLOBALS['unsentRecoveryClaimHook']=static function()use($launch,$config,$hold,&$child):void{$child=$launch($config['private_db_path'],$hold);};
        $thrown=false;try{$invoke();}catch(PDOException){$thrown=true;}$join($child);
        $status=$pdo->query('SELECT status FROM '.$table)->fetchColumn();$same($kind==='long_receipt'?'sending':'pending',$status,$kind.' preserves correct Core state after claim failure');
        $same($kind==='long_receipt',$thrown,$kind.' completion persistence limit explicit');$same(0,$http->attempts[$kind==='achievement'?'achievement':'receipt']??0,$kind.' POST transport remains zero');
        $invoke();$final=$pdo->query('SELECT status FROM '.$table)->fetchColumn();$same($kind==='long_receipt'?'sending':'sent',$final,$kind.' recovery or conservative hold');
        $same($kind==='long_receipt'?0:1,$http->attempts[$kind==='achievement'?'achievement':'receipt']??0,$kind.' no duplicate POST');
        if($kind!=='achievement'){$same($at+600,(int)$pdo->query('SELECT closes_at FROM sessions')->fetchColumn(),$kind.' fixed cutoff retained');}
        $cases[$kind]=['writer_lock_ms'=>$hold,'first_core_status'=>$status,'final_core_status'=>$final,'posts'=>$http->attempts[$kind==='achievement'?'achievement':'receipt']??0,'completion_write_failed'=>$thrown];$context->roster->close();
    }
    // Failure after observing an old unknown claim must not turn that operation into known-unsent.
    $path=$root.'/commit.sqlite';$db=new Database($path,200);$pdo=new UnsentCommitFailurePDO('sqlite:'.$path);$pdo->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);$pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE,PDO::FETCH_ASSOC);
    (new ReflectionProperty(Database::class,'connection'))->setValue($db,$pdo);$http=new RecoveryFakeHttp(new RuntimeFixtureHttp());$http->postModes['ordinary']='timeout';
    $client=new ServerOperationsClient($db,$http,'fictional_unsent_token_000000',['enabled'=>true,'posts_enabled'=>true]);$client->postText(DiscordRestClient::TEXT_CHANNEL_ID,'old unknown','commit-case');$pdo->failNextCommit=true;
    $result=$client->postText(DiscordRestClient::TEXT_CHANNEL_ID,'old unknown','commit-case');$same(TransportOutcome::Uncertain,$result->outcome,'observed unknown plus commit exception remains unknown');$same('uncertain',$pdo->query('SELECT status FROM operation_receipts')->fetchColumn(),'commit exception cannot reset old uncertainty');$same(1,$http->attempts['ordinary'],'commit exception never causes extra POST');
    $cases['existing_unknown_commit_failure']=['outcome'=>$result->outcome->value,'old_uncertain_preserved'=>true,'total_posts'=>1];
    $summary=['status'=>'LOCAL_CLAIM_LOCK_RECOVERY_VERIFIED','assertions'=>$checks,'source_sha256_server_operations_client'=>hash_file('sha256',dirname(__DIR__).'/src/Discord/ServerOperationsClient.php'),'cases'=>$cases,'real_external_calls'=>0];
    $override=getenv('RADIO_STAMP_TEST_ARTIFACT_DIR');$stage=is_string($override)&&$override!==''?$override:getenv('RADIO_STAMP_TEST_ARTIFACT_DIR');if(!is_dir($stage)){mkdir($stage,0700,true);}file_put_contents($stage.'/claim-lock.after.safe.json',json_encode($summary,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES)."\n");
    return $summary;
}
if(($argv[1]??null)==='--claim-lock-only'){$targeted=unsentClaimLockCases();echo 'Unsent claim lock: '.$targeted['assertions']." targeted assertions passed (fake transport; no prior 167-case rerun).\n";exit(0);}
$checks=0;$scenarios=[];$logs='';
$same=static function(mixed $expected,mixed $actual,string $why)use(&$checks):void{++$checks;if($expected!==$actual){throw new RuntimeException('Unsent recovery: '.$why.' expected='.json_encode($expected).' actual='.json_encode($actual));}};
$user='900000000000000101';$other='900000000000000102';$third='900000000000000103';$outside='900000000000000199';$voice=DiscordRestClient::VOICE_CHANNEL_ID;
$root=sys_get_temp_dir().'/radio-unsent-recovery-'.bin2hex(random_bytes(8));mkdir($root,0700);
$make=static function(string $name)use($root,&$logs):array{
    $folder=$root.'/'.$name;mkdir($folder,0700);mkdir($folder.'/private',0700);
    $config=runtimeFixtureConfig($folder,'best_effort',true);$config['participant_scope']='guild';$config['participant_user_ids']=[];$config['live_automation_enabled']=true;
    $config['schedule']=['daily_times'=>[],'one_off_times'=>[['at'=>'2026-10-20 14:30','slot_id'=>'morning']]];$config['diagnostics_enabled']=true;
    $clock=new FrozenClock('2026-10-20T14:30:00+09:00');$inner=new RuntimeFixtureHttp();$http=new RecoveryFakeHttp($inner);
    $runtime=new OperationsRuntime($http,fn()=>$inner->transport(),$clock);$guard=static function()use($http):bool{if($http->onGuard!==null){($http->onGuard)();}return $http->permit;};
    $diag=SafeDiagnostics::fromConfig($config,static function(string $line)use(&$logs):void{$logs.=$line;});$context=$runtime->build($config,$guard,null,$diag);
    return compact('folder','config','clock','inner','http','runtime','guard','diag','context');
};
$receiptRow=static function(array $f):array{return $f['context']->db->pdo()->query('SELECT * FROM receipt_announcements')->fetch()?:[];};
$sessionRow=static function(array $f):array{return $f['context']->db->pdo()->query("SELECT * FROM sessions WHERE id='2026-10-20_1430'")->fetch()?:[];};
$receiptMessages=static fn(array $f):int=>count(array_filter($f['inner']->messages,static fn(array $m):bool=>($m['components']??[])!==[]));

// Each known pre-PATCH rejection releases only its owned claim and rechecks new observations/fence.
foreach(['observation_expired','source_guard_rejected','worker_guard_exhausted','local_rate_cooldown']as$case){
    $db=new Database(':memory:');$inner=new RuntimeFixtureHttp();$inner->voices[$user]=$voice;$http=new RecoveryFakeHttp($inner);
    $control=(object)['now'=>1000.0,'source'=>false,'generation'=>1];
    $http->afterVoice=static function(string $unused,int $reads)use($case,$http):void{if($reads===2){if($case==='observation_expired'){$http->mono+=0.3;}if($case==='worker_guard_exhausted'){$http->permit=false;}}};
    $options=['enabled'=>true,'member_actions_enabled'=>true,'accept_voice_race_risk'=>true,'now'=>fn():float=>$control->now,'monotonic'=>fn():float=>$http->mono,
        'request_guard'=>fn():bool=>$http->permit,'source_guard'=>fn(string $unused,?MemberActionFence $f):bool=>($case!=='source_guard_rejected'||$control->source)&&$f?->generation===$control->generation];
    $client=new ServerOperationsClient($db,$http,'fictional_unsent_token_000000',$options);
    if($case==='local_rate_cooldown'){$db->pdo()->exec("INSERT INTO discord_rest_limits(limit_key,until_at) VALUES('route:member:voice',1001)");}
    $key='disconnect:2026-10-20_1430:'.$user;$first=$client->tryRelocate($user,null,$key,new MemberActionFence(DiscordRestClient::GUILD_ID,$user,$voice,1,1000));
    $same(false,$first->ok,$case.' refuses first attempt');$same(TransportOutcome::NotStarted,$first->outcome,$case.' proves mutation transport not started');
    $same(0,$http->attempts['member']??0,$case.' no first PATCH');$same(0,(int)$db->pdo()->query('SELECT COUNT(*) FROM operation_receipts')->fetchColumn(),$case.' owned unsent claim released');
    $control->source=true;$control->generation=2;$http->permit=true;$http->afterVoice=null;$control->now=1002;
    $restarted=new ServerOperationsClient($db,$http,'fictional_unsent_token_000000',$options);
    $old=$restarted->tryRelocate($user,null,$key,new MemberActionFence(DiscordRestClient::GUILD_ID,$user,$voice,1,1000));
    $same(false,$old->ok,$case.' old generation never reused');$same(0,$http->attempts['member']??0,$case.' old fence makes no PATCH');
    $second=$restarted->tryRelocate($user,null,$key,new MemberActionFence(DiscordRestClient::GUILD_ID,$user,$voice,2,1002));
    $same(true,$second->ok,$case.' recovery applies after fresh fence');$same(TransportOutcome::Succeeded,$second->outcome,$case.' success classified');
    $same(1,$http->attempts['member']??0,$case.' exactly one PATCH');$same(null,$inner->voices[$user],$case.' disconnected on recovery');
    $same('sent',$db->pdo()->query('SELECT status FROM operation_receipts')->fetchColumn(),$case.' success persisted');
    $inner->voices[$user]=$voice;$restarted->tryRelocate($user,null,$key,new MemberActionFence(DiscordRestClient::GUILD_ID,$user,$voice,2,1002));
    $same(1,$http->attempts['member'],$case.' applied operation not repeated');
    $scenarios[$case]=['first'=>$first->outcome->value,'recovered'=>$second->outcome->value,'patches'=>1,'new_fence_required'=>true];
}

// Timeout/exception/readback loss crossed transport: preserve ambiguity and never PATCH again.
foreach(['timeout','exception','readback_loss']as$mode){
    $db=new Database(':memory:');$inner=new RuntimeFixtureHttp();$inner->voices[$user]=$voice;$http=new RecoveryFakeHttp($inner);$http->patchMode=$mode;
    $options=['enabled'=>true,'member_actions_enabled'=>true,'accept_voice_race_risk'=>true];$client=new ServerOperationsClient($db,$http,'fictional_unsent_token_000000',$options);
    $first=$client->tryRelocate($user,null,'unknown-'.$mode);$same(TransportOutcome::Uncertain,$first->outcome,$mode.' remains unknown');$same('uncertain',$db->pdo()->query('SELECT status FROM operation_receipts')->fetchColumn(),$mode.' held durably');
    $http->patchMode='ok';$http->readbackLoss=false;$inner->voices[$user]=$voice;$new=new ServerOperationsClient($db,$http,'fictional_unsent_token_000000',$options);$new->tryRelocate($user,null,'unknown-'.$mode);
    $same(1,$http->attempts['member'],$mode.' restart never retries unknown PATCH');
}

// Lost/stale ownership cannot erase another claim, even when this invocation did not send.
$db=new Database(':memory:');$inner=new RuntimeFixtureHttp();$inner->voices[$user]=$voice;$http=new RecoveryFakeHttp($inner);
$client=new ServerOperationsClient($db,$http,'fictional_unsent_token_000000',['enabled'=>true,'member_actions_enabled'=>true,'accept_voice_race_risk'=>true,
    'source_guard'=>static function()use($db):bool{$db->pdo()->exec("UPDATE operation_receipts SET claim_owner='other-owner'");return false;}]);
$lost=$client->tryRelocate($user,null,'owner-test');$same('operation_claim_lost',$lost->code,'stale finisher cannot release replacement owner');$same(TransportOutcome::Uncertain,$lost->outcome,'claim loss stays conservative');
$same('other-owner',$db->pdo()->query('SELECT claim_owner FROM operation_receipts')->fetchColumn(),'replacement owner retained');$same(0,$http->attempts['member']??0,'claim loss no PATCH');

// A failed shared-receipt command recovers through ordinary worker tick, without a new command.
$f=$make('receipt-guard');$same(true,OperationsConfig::ready($f['config']),'all-feature production config ready');$f['inner']->voices=[$user=>$voice,$other=>$voice,$third=>$voice,$outside=>'900000000000000198'];
$f['http']->denyAfterVoice=true;$first=$f['context']->service->openAttendance($user,'receipt-guard-open');
$same('receipt_announcement_unconfirmed',$first['code'],'post0 receipt command reports unconfirmed');$same(0,$f['http']->attempts['receipt']??0,'no receipt transport on exhausted guard');$same('pending',$receiptRow($f)['status'],'Core preserves known-unsent as pending');
$fixedCutoff=(int)$sessionRow($f)['closes_at'];$f['http']->denyAfterVoice=false;$f['http']->permit=true;
$f['context']->roster->close();$f['context']=$f['runtime']->build($f['config'],$f['guard'],null,$f['diag']);$f['runtime']->cycle($f['context'],$f['guard']);
$same('sent',$receiptRow($f)['status'],'ordinary restarted worker recovers receipt');$same(1,$receiptMessages($f),'one actual shared receipt');$same($fixedCutoff,(int)$sessionRow($f)['closes_at'],'recovery never extends original cutoff');
$same(0,(int)$f['context']->db->pdo()->query('SELECT COUNT(*) FROM attendance')->fetchColumn(),'automatic receipt recovery never registers caller');
foreach([$user,$other]as$n=>$person){$same('attended',$f['context']->service->attend('2026-10-20_1430',$person,'attend-'.$n)['code'],'individual attendance after recovered receipt');}
$f['runtime']->cycle($f['context'],$f['guard']);$f['runtime']->cycle($f['context'],$f['guard']);
$same(2,(int)$f['context']->db->pdo()->query('SELECT COUNT(*) FROM attendance')->fetchColumn(),'duplicate cycles never recount');
$same(2,count(array_filter($f['inner']->messages,static fn(array $m):bool=>$m['channel_id']===DiscordRestClient::RECORDS_CHANNEL_ID)),'one title notification per actual participant');
$f['clock']->advance(540);$f['runtime']->cycle($f['context'],$f['guard']);$same(0,$f['http']->attempts['member']??0,'no member cut at warning');
$f['clock']->advance(59);$f['runtime']->cycle($f['context'],$f['guard']);$same(0,$f['http']->attempts['member']??0,'full warning grace preserved');
$f['clock']->advance(1);$f['http']->voiceReads=[];$f['http']->slowClosure=true;$f['runtime']->cycle($f['context'],$f['guard']);
$same(0,$f['http']->attempts['member']??0,'slow final observations make no PATCH');$same('closing',$sessionRow($f)['status'],'slow observations retain closure pending');
$f['http']->slowClosure=false;$f['runtime']->cycle($f['context'],$f['guard']);$same('closed',$sessionRow($f)['status'],'next normal tick recovers whole closure');
$same(3,$f['http']->attempts['member']??0,'participants and non-clicking lingering person each disconnected once');$same('900000000000000198',$f['inner']->voices[$outside],'other VC preserved');
$f['runtime']->cycle($f['context'],$f['guard']);$same(3,$f['http']->attempts['member'],'closed session never cuts twice');$f['context']->roster->close();
$scenarios['full_recovered_flow']=['receipt_posts'=>1,'attendance'=>2,'member_patches'=>3,'non_clicker_included'=>true,'other_voice_untouched'=>true,'cutoff_unchanged'=>true];

// Explicit rejection retries only after its cooldown; unknown responses stay held.
foreach(['429','timeout','exception','500','invalid_success']as$mode){
    $x=$make('receipt-'.$mode);$x['inner']->voices[$user]=$voice;$x['http']->postModes['receipt']=$mode;$x['context']->service->openAttendance($user,'open-'.$mode);
    $expected=$mode==='429'?'pending':'uncertain';$same($expected,$receiptRow($x)['status'],$mode.' Core delivery classification');
    $before=$x['http']->attempts['receipt'];$x['http']->postModes['receipt']='ok';$x['runtime']->cycle($x['context'],$x['guard']);
    $same($before,$x['http']->attempts['receipt'],$mode.' no immediate duplicate or cooldown violation');
    if($mode==='429'){usleep(300000);$x['clock']->advance(1);$x['context']->roster->close();$x['context']=$x['runtime']->build($x['config'],$x['guard'],null,$x['diag']);$x['runtime']->cycle($x['context'],$x['guard']);$same('sent',$receiptRow($x)['status'],'429 recovers on restarted tick after cooldown');$same(2,$x['http']->attempts['receipt'],'one rejected POST plus one applied POST');$same(1,$receiptMessages($x),'rejection never produced another message');}
    else{$x['clock']->advance(700);$x['runtime']->cycle($x['context'],$x['guard']);$same('uncertain',$receiptRow($x)['status'],$mode.' never expired as definitely unsent');$same(null,$receiptRow($x)['expired_at'],$mode.' unknown is not reset');$same($before,$x['http']->attempts['receipt'],$mode.' unknown receipt never reposted');}
    $x['context']->roster->close();
}

// Concurrent sender sees in-flight unknown rather than misclassifying the operation as unsent.
$db=new Database(':memory:');$inner=new RuntimeFixtureHttp();$http=new RecoveryFakeHttp($inner);$options=['enabled'=>true,'posts_enabled'=>true];
$a=new ServerOperationsClient($db,$http,'fictional_unsent_token_000000',$options);$b=new ServerOperationsClient($db,$http,'fictional_unsent_token_000000',$options);$inflight=null;
$http->duringPost=static function()use(&$inflight,$b):void{$inflight=$b->postText(DiscordRestClient::TEXT_CHANNEL_ID,'same payload','same-operation');};
$sent=$a->postText(DiscordRestClient::TEXT_CHANNEL_ID,'same payload','same-operation');$same(TransportOutcome::Uncertain,$inflight->outcome,'parallel claimant cannot release in-flight send');$same(true,$sent->ok,'actual owner commits');$same(1,$http->attempts['ordinary'],'one parallel transport');$same('sent',$db->pdo()->query('SELECT status FROM operation_receipts')->fetchColumn(),'parallel observer cannot roll back owner');

// Shared-receipt stale owner cannot downgrade another caller's sending state to pending.
$o=$make('receipt-owner');$o['inner']->voices[$user]=$voice;$o['http']->postModes['receipt']='429';
$o['http']->duringPost=static function(string $kind)use($o):void{if($kind==='receipt'){$o['context']->db->pdo()->exec("UPDATE receipt_announcements SET claim_owner='replacement-owner'");}};
$o['context']->service->openAttendance($user,'owner-open');$same('sending',$receiptRow($o)['status'],'stale Core completion does not reopen another claim');$same('replacement-owner',$receiptRow($o)['claim_owner'],'Core replacement owner retained');$o['context']->roster->close();

// A definitely-unsent receipt expires without posting, counting or cutting; gate close itself may retry.
$e=$make('expiry');$e['inner']->voices[$user]=$voice;$e['runtime']->cycle($e['context'],$e['guard']);$e['http']->denyAfterVoice=true;$e['context']->service->openAttendance($user,'expire-open');$e['http']->denyAfterVoice=false;$e['http']->permit=true;
$same(1<<20,(int)$e['inner']->allow&(1<<20),'fixture gate really opened before receipt failure');$e['clock']->advance(600);
$e['http']->afterVoice=null;$e['http']->permit=false;$expired=(new ReflectionMethod(RadioService::class,'processSession'))->invoke($e['context']->service,'2026-10-20_1430');
$same('receipt_expired_room_close_pending',$expired['code'],'failed expired gate close stays retryable');$same('closing',$sessionRow($e)['status'],'never reports closed with gate failure');$same(0,$e['http']->attempts['receipt']??0,'expired known-unsent never posts');
$e['http']->permit=true;$e['runtime']->cycle($e['context'],$e['guard']);$same('closed',$sessionRow($e)['status'],'expired gate eventually closes');$same(1<<20,(int)$e['inner']->deny&(1<<20),'expired receipt closes CONNECT');$same(0,$e['http']->attempts['member']??0,'no unannounced cut after unsent expiry');$same(0,(int)$e['context']->db->pdo()->query('SELECT COUNT(*) FROM attendance')->fetchColumn(),'expiry does not count');$same(true,$receiptRow($e)['expired_at']!==null,'known-unsent expiry explicit');$e['context']->roster->close();

// The last transport guard also prevents an unsent receipt that reaches its fixed cutoff mid-attempt.
$z=$make('cutoff-during-guard');$z['inner']->voices[$user]=$voice;
$z['http']->afterVoice=static function()use($z):void{$z['http']->onGuard=static function()use($z):void{$z['clock']->advance(600);$z['http']->onGuard=null;};};
$z['context']->service->openAttendance($user,'cutoff-open');$same(0,$z['http']->attempts['receipt']??0,'last transport guard refuses at fixed cutoff');
$same(true,$receiptRow($z)['expired_at']!==null,'owned sending receipt expires after definite no-send at last guard');$same(1792474800,(int)$sessionRow($z)['closes_at'],'mid-attempt fixed cutoff retained');
$z['http']->afterVoice=null;$z['runtime']->cycle($z['context'],$z['guard']);$same(0,$z['http']->attempts['member']??0,'no member action when receipt never applied');$same('closed',$sessionRow($z)['status'],'mid-attempt expiry safely closes gate');$z['context']->roster->close();

// Achievement notices share rich outcomes: no-repeat mention policy holds after uncertain transport.
foreach(['guard','429','timeout']as$mode){
    $n=$make('notice-'.$mode);$n['context']->db->pdo()->prepare("INSERT INTO participants(user_id,total_count) VALUES(?,1)")->execute([$user]);
    $n['context']->client->grantTitle($user,1);$n['context']->db->pdo()->prepare("INSERT INTO achievement_notices(user_id,threshold,total_count,status,created_at) VALUES(?,1,1,'pending',?)")->execute([$user,$n['clock']->now()->getTimestamp()]);
    if($mode==='guard'){$n['http']->permit=false;}else{$n['http']->postModes['achievement']=$mode;}
    $flush=new ReflectionMethod(RadioService::class,'flushAchievementNotices');$flush->invoke($n['context']->service);
    $status=$n['context']->db->pdo()->query('SELECT status FROM achievement_notices')->fetchColumn();$same($mode==='timeout'?'uncertain':'pending',$status,$mode.' achievement Core classification');
    $n['http']->permit=true;$n['http']->postModes['achievement']='ok';if($mode==='429'){usleep(300000);}
    $flush->invoke($n['context']->service);$flush->invoke($n['context']->service);
    $same($mode==='timeout'?'uncertain':'sent',$n['context']->db->pdo()->query('SELECT status FROM achievement_notices')->fetchColumn(),$mode.' achievement recovery or hold');
    $same($mode==='timeout'?0:1,count(array_filter($n['inner']->messages,static fn(array $m):bool=>$m['channel_id']===DiscordRestClient::RECORDS_CHANNEL_ID)),$mode.' actual mentions at most once');$n['context']->roster->close();
}

// Generic ordinary posts release a preflight refusal; sent/uncertain and old ownerless rows stay protected.
$db=new Database(':memory:');$inner=new RuntimeFixtureHttp();$http=new RecoveryFakeHttp($inner);$http->permit=false;
$a=new ServerOperationsClient($db,$http,'fictional_unsent_token_000000',['enabled'=>true,'posts_enabled'=>true,'request_guard'=>fn():bool=>$http->permit]);
$same(TransportOutcome::NotStarted,$a->postText(DiscordRestClient::TEXT_CHANNEL_ID,'ordinary','ordinary-retry')->outcome,'ordinary preflight classified');$http->permit=true;$same(true,$a->postText(DiscordRestClient::TEXT_CHANNEL_ID,'ordinary','ordinary-retry')->ok,'ordinary unsent recovers');$a->postText(DiscordRestClient::TEXT_CHANNEL_ID,'ordinary','ordinary-retry');$same(1,$http->attempts['ordinary'],'ordinary success not duplicated');
$db->pdo()->exec("UPDATE operation_receipts SET status='uncertain',claim_owner=NULL");$same(TransportOutcome::Uncertain,$a->postText(DiscordRestClient::TEXT_CHANNEL_ID,'ordinary','ordinary-retry')->outcome,'ownerless legacy uncertainty never reset');$same(1,$http->attempts['ordinary'],'legacy uncertainty no transport');

// Migration adds only ownership metadata; a genuine old-schema uncertain row is never reset.
$oldRow=$db->pdo()->query('SELECT * FROM operation_receipts')->fetch();$legacy=new Database(':memory:');
$legacy->pdo()->exec("CREATE TABLE operation_receipts(operation_key TEXT PRIMARY KEY,kind TEXT NOT NULL,target TEXT NOT NULL,payload_sha256 TEXT NOT NULL,status TEXT NOT NULL CHECK(status IN ('sending','sent','uncertain')),message_id TEXT,created_at INTEGER NOT NULL,finished_at INTEGER)");
$legacy->pdo()->prepare('INSERT INTO operation_receipts(operation_key,kind,target,payload_sha256,status,message_id,created_at,finished_at) VALUES(?,?,?,?,?,?,?,?)')->execute(array_values(array_diff_key($oldRow,['claim_owner'=>true])));
$legacyHttp=new RecoveryFakeHttp(new RuntimeFixtureHttp());$legacyClient=new ServerOperationsClient($legacy,$legacyHttp,'fictional_unsent_token_000000',['enabled'=>true,'posts_enabled'=>true]);
$same(true,in_array('claim_owner',$legacy->pdo()->query('PRAGMA table_info(operation_receipts)')->fetchAll(PDO::FETCH_COLUMN,1),true),'legacy receipt ownership migration');
$same(TransportOutcome::Uncertain,$legacyClient->postText(DiscordRestClient::TEXT_CHANNEL_ID,'ordinary','ordinary-retry')->outcome,'old schema uncertain remains held');$same(0,$legacyHttp->attempts['ordinary']??0,'migration never sends old uncertainty');

// Real local processes compete for a single owned transport claim, using fake adapters only.
$raceDb=$root.'/parallel.sqlite';$seedDb=new Database($raceDb);new ServerOperationsClient($seedDb,new RuntimeFixtureHttp(),'fictional_unsent_token_000000',['enabled'=>true,'posts_enabled'=>true]);unset($seedDb);
$trace=$root.'/parallel-posts.txt';$children=[];
for($i=0;$i<4;$i++){$args=[PHP_BINARY];if(($ini=php_ini_loaded_file())!==false){array_push($args,'-c',$ini);}array_push($args,__FILE__,'--post-child',$raceDb,$trace);$pipes=[];$process=proc_open($args,[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes,null,null,['bypass_shell'=>true]);$same(true,is_resource($process),'start fake-transport race child');fclose($pipes[0]);$children[]=[$process,$pipes];}
foreach($children as[$process,$pipes]){$out=stream_get_contents($pipes[1]);$err=stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);$exit=proc_close($process);$same(0,$exit,'race child exits normally');$same('',$err,'race child emits no error');$same(true,is_array(json_decode($out,true)),'race child returns bounded outcome');}
$same(1,count(file($trace,FILE_IGNORE_NEW_LINES|FILE_SKIP_EMPTY_LINES)),'four processes enter fake POST exactly once');$raceRead=new Database($raceDb);$same('sent',$raceRead->pdo()->query('SELECT status FROM operation_receipts')->fetchColumn(),'multiprocess owner commits one sent record');unset($raceRead);

$claimLockSummary=unsentClaimLockCases();$checks+=$claimLockSummary['assertions'];$scenarios['claim_lock']=$claimLockSummary['cases'];
$rows=array_map(static fn(string $line):array=>json_decode($line,true,32,JSON_THROW_ON_ERROR),array_filter(explode("\n",trim($logs))));
$outcomes=array_values(array_unique(array_filter(array_column($rows,'outcome'))));sort($outcomes);
foreach(['not_started','rejected','succeeded','uncertain']as$value){$same(true,in_array($value,$outcomes,true),'diagnostics retain '.$value);}
$secretLog='';$diag=SafeDiagnostics::fromConfig(['diagnostics_enabled'=>true],static function(string $line)use(&$secretLog):void{$secretLog.=$line;});
$diag->record('rest',['ok'=>false,'code'=>'other_code','outcome'=>'CANARY_SECRET_OUTCOME','token'=>'CANARY_SECRET_TOKEN','url'=>'https://CANARY_SECRET_URL.invalid']);
$same(false,str_contains($logs.$secretLog,'CANARY_SECRET'),'untrusted diagnostic fields never leak');$same(false,str_contains($logs,'fictional_runtime_bot_token'),'credentials never logged');
$override=getenv('RADIO_STAMP_TEST_ARTIFACT_DIR');$stage=is_string($override)&&$override!==''?$override:getenv('RADIO_STAMP_TEST_ARTIFACT_DIR');if(!is_dir($stage)){mkdir($stage,0700,true);}
file_put_contents($stage.'/verification.safe.json',json_encode(['status'=>'LOCAL_UNSENT_RECOVERY_VERIFIED','assertions'=>$checks,'scenarios'=>$scenarios,'outcomes_observed'=>$outcomes,'real_api_calls'=>0,'external_connections'=>0,'server_or_discord_changes'=>0],JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES)."\n");
file_put_contents($stage.'/diagnostics.safe.jsonl',$logs.$secretLog);
echo "Unsent recovery: $checks assertions passed (fresh guards, fixed receipt deadline, owned retries and ambiguous hold; fake adapters only).\n";
}
