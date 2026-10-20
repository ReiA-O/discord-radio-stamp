<?php
declare(strict_types=1);
require_once __DIR__.'/artifact_directory.php';
$project=str_replace('\\','/',dirname(__DIR__));
$package=getenv('RADIO_STAMP_TEST_ARTIFACT_DIR');
if(!is_dir($package)&&!mkdir($package,0700,true)&&!is_dir($package)){throw new RuntimeException('test_artifact_directory_unavailable');}
require $project.'/bootstrap.php';
require __DIR__.'/fixtures/OperationsRuntimeFixture.php';
use RadioStamp\Core\{Database,FrozenClock};
use RadioStamp\Discord\{HttpAdapter,HttpRequest,HttpResponse,DiscordRestClient};
use RadioStamp\Operations\{SafeDiagnostics,OperationsRuntime,OperationsConfig,OperationsCli};
use RadioStamp\Http\{InteractionController,InteractionQueue,InteractionWorker,EndpointDelivery,Response,PostResponseDriver,FollowupSink};
use RadioStamp\Security\TokenCipher;

final class FailureHttp implements HttpAdapter
{
    public bool $active=false;public array $attempts=[];public ?Closure $onIdentity=null;
    public function __construct(public RuntimeFixtureHttp $inner,public string $mode){}
    public function exchange(HttpRequest $request):HttpResponse
    {
        $path=parse_url($request->url(),PHP_URL_PATH);$method=$request->method();
        if($path==='/api/v10/users/@me'&&$this->onIdentity!==null){$hook=$this->onIdentity;$this->onIdentity=null;$hook();}
        if($this->active){
            if($this->mode==='followup403'&&$method==='PATCH'&&str_contains($path,'/webhooks/')){return new HttpResponse(403,[],'{"code":50013}');}
            if($this->mode==='voice_timeout'&&str_contains($path,'/voice-states/')){return new HttpResponse(0,[],'','transport_timeout');}
            if($this->mode==='room403'&&$method==='PUT'&&str_contains($path,'/permissions/')){return new HttpResponse(403,[],'{"code":50013}');}
            if($this->mode==='warning403'&&$method==='POST'&&str_ends_with($path,'/messages')){return new HttpResponse(403,[],'{"code":50013}');}
            if($method==='PATCH'&&preg_match('~/members/[0-9]+$~D',$path)){
                $this->attempts[]=$path;
                if($this->mode==='member403'){return new HttpResponse(403,[],'{"code":50013}');}
                if($this->mode==='member429'){return new HttpResponse(429,[], '{"retry_after":1}');}
                if($this->mode==='member_exception'){throw new RuntimeException('CANARY_SECRET_EXCEPTION https://secret.invalid/token');}
            }
        }
        return $this->inner->exchange($request);
    }
}
$checks=0;$assert=static function(bool $ok,string $why)use(&$checks):void{if(!$ok){throw new RuntimeException($why);}$checks++;};
$approved=['800000000000001021','800000000000001020','800000000000001022'];$voice=DiscordRestClient::VOICE_CHANNEL_ID;
$temp=str_replace('\\','/',sys_get_temp_dir().'/radio-local-repair-'.bin2hex(random_bytes(8)));mkdir($temp,0700);
$allLogs='';$scenarios=[];
$events=static fn(string $log):array=>array_map(static fn($line)=>json_decode($line,true,32,JSON_THROW_ON_ERROR),array_filter(explode("\n",trim($log))));
$has=static fn(array $rows,string $stage,?string $code=null):bool=>count(array_filter($rows,static fn($row)=>$row['stage']===$stage&&($code===null||($row['code']??null)===$code)))>0;
try{
    $memory='';$diag=SafeDiagnostics::fromConfig(['diagnostics_enabled'=>true,'participant_user_ids'=>$approved],static function($line)use(&$memory){$memory.=$line;});
    $diag->context('900000000000000123',$approved[1],'card');
    $diag->record('worker_result',['ok'=>false,'code'=>'CANARY_SECRET_CODE','transport'=>'CANARY_SECRET_TRANSPORT',
        'actor'=>'CANARY_SECRET_NAME','url'=>'https://secret.invalid/token','token'=>'CANARY_SECRET_TOKEN','headers'=>['Authorization: CANARY_SECRET_HEADER'],
        'exception'=>'CANARY_SECRET_CLASS','body'=>'CANARY_SECRET_BODY','duration_ms'=>3]);
    $diag->exception('worker_result',new RuntimeException('CANARY_SECRET_EXCEPTION'));
    $diag->record('CANARY_SECRET_STAGE',['code'=>'ok']);
    $assert(!str_contains($memory,'CANARY_SECRET')&&!str_contains($memory,$approved[1]),'strict schema drops all secret and personal canaries');
    $rows=$events($memory);$assert(count($rows)===2&&$rows[0]['code']==='other_code'&&$rows[0]['actor']==='p2'&&$rows[1]['exception']==='RuntimeException','safe aliases and exception category retained');
    $assert(preg_match('/\A[0-9a-f]{12}\z/D',$rows[0]['trace'])===1&&is_int($rows[0]['elapsed_ms']),'noncredential trace and elapsed time');
    foreach(['slot_binding_required','session_slot_binding_conflict','schedule_slot_invalid','schedule_slot_conflict']as$slotCode){
        $beforeCount=count($events($memory));$diag->record('worker_result',['ok'=>false,'code'=>$slotCode,'token'=>'CANARY_SECRET_SLOT','body'=>'CANARY_SECRET_BODY']);
        $slotEvents=$events($memory);$assert(count($slotEvents)===$beforeCount+1&&$slotEvents[array_key_last($slotEvents)]['code']===$slotCode,'new slot failure retains only its fixed diagnostic name');
    }
    $diag->record('worker_result',['code'=>'CANARY_SECRET_UNKNOWN_SLOT']);$unknownSlotEvents=$events($memory);
    $assert($unknownSlotEvents[array_key_last($unknownSlotEvents)]['code']==='other_code'&&!str_contains($memory,'CANARY_SECRET'),'unknown diagnostic code and slot canaries remain suppressed');
    $disabled='';SafeDiagnostics::fromConfig(['diagnostics_enabled'=>false],static function($line)use(&$disabled){$disabled.=$line;})->record('cycle',['code'=>'ok']);
    $assert($disabled==='','diagnostics default/off produces no output');
    ob_start();SafeDiagnostics::fromConfig(['diagnostics_enabled'=>true],static function(){throw new RuntimeException('CANARY_SECRET_SINK');})->record('cycle',['code'=>'ok']);$assert(ob_get_clean()==='','sink failure cannot break processing or expose errors');
    $allLogs.=$memory;
    $lockRoot=$temp.'/diagnostic-lock';mkdir($lockRoot,0700);
    $fileDiag=SafeDiagnostics::fromConfig(['diagnostics_enabled'=>true,'participant_user_ids'=>$approved,'private_root'=>$lockRoot]);
    $fileDiag->record('cycle',['code'=>'ok']);$handle=fopen($lockRoot.'/operations-diagnostic.safe.jsonl','ab');flock($handle,LOCK_EX);
    $before=hrtime(true);$fileDiag->record('cycle',['code'=>'ok']);$assert((hrtime(true)-$before)/1000000<500,'diagnostic lock never blocks ACK path');
    flock($handle,LOCK_UN);fclose($handle);$fileDiag->record('cycle',['code'=>'ok']);
    $fileEvents=$events(file_get_contents($lockRoot.'/operations-diagnostic.safe.jsonl'));
    $assert(($fileEvents[array_key_last($fileEvents)]['dropped_events']??0)===1,'next successful event reports dropped log write');

    $after=0;$driver=new class implements PostResponseDriver{public function available():bool{return true;}public function finish():bool{return false;}};
    $delivery=new EndpointDelivery(new Response(200,['type'=>5],'900000000000000124'),static function()use(&$after){$after++;},$diag);
    $assert(!$delivery->deliver($driver,static fn($response)=>null)&&$after===0,'failed ACK finish never runs callback');
    $assert($has($events($memory),'ack_finish','unavailable'),'ACK finish failure observable without raw output');
    $delivery=new EndpointDelivery(new Response(200,['type'=>5],'900000000000000126'),null,$diag);
    $assert(!$delivery->deliver($driver,static function(){throw new TypeError('CANARY_SECRET_EMITTER');}),'emitter exception does not escape');
    $assert($has($events($memory),'ack_emit','exception')&&!str_contains($memory,'CANARY_SECRET'),'emitter exception logged at correct stage without message');

    $queueRoot=$temp.'/queue';mkdir($queueRoot,0700);$db=new Database($queueRoot.'/db.sqlite',200);
    $queue=new InteractionQueue($db->pdo(),new TokenCipher(str_repeat('47',32)));
    $config=runtimeFixtureConfig($queueRoot,'best_effort');$config['participant_user_ids']=$approved;
    $config['admin_user_ids']=$approved;$config['mode']='mock';$config['admin_validation_enabled']=true;$config['diagnostics_enabled']=true;
    $keys=sodium_crypto_sign_seed_keypair(str_repeat("\x17",32));$config['public_key_hex']=bin2hex(sodium_crypto_sign_publickey($keys));
    $clock=new FrozenClock('2026-10-20T11:42:00+09:00');
    $controller=new InteractionController($config,$queue,$clock,true,$approved,'operations',$diag);
    $body=json_encode(['id'=>'900000000000000125','application_id'=>DiscordRestClient::APPLICATION_ID,'type'=>2,'guild_id'=>DiscordRestClient::GUILD_ID,
        'channel_id'=>$voice,'token'=>'CANARY_SECRET_INTERACTION','member'=>['user'=>['id'=>$approved[1],'username'=>'CANARY_SECRET_NAME']],
        'data'=>['type'=>1,'name'=>'radio-attend']],JSON_THROW_ON_ERROR);
    $timestamp=(string)$clock->now()->getTimestamp();$headers=['x-signature-timestamp'=>$timestamp,'x-signature-ed25519'=>bin2hex(sodium_crypto_sign_detached($timestamp.$body,sodium_crypto_sign_secretkey($keys)))];
    $locker=new PDO('sqlite:'.$queueRoot.'/db.sqlite');$locker->exec('BEGIN IMMEDIATE');
    $before=hrtime(true);$response=$controller->handle('POST',$headers,$body);$elapsed=(int)((hrtime(true)-$before)/1000000);$locker->exec('ROLLBACK');
    $assert($response->status===503&&$elapsed<1500,'actual 200ms SQLite lock failure is bounded before ACK');
    $rows=$events($memory);$assert($has($rows,'http_queue','exception')&&$has($rows,'http_controller','private_queue_unavailable'),'queue exception and HTTP503 observable');
    $assert(count(array_filter($rows,static fn($r)=>($r['exception']??null)==='PDOException'))>0,'SQLite exception category retained');
    $assert(!str_contains($memory,'CANARY_SECRET')&&!str_contains($memory,'https://'),'request secrets and raw names never logged');
    $response=$controller->handle('POST',$headers,$body);$assert($response->status===200&&$response->body['type']===5,'same allowed user receives ACK when lock absent');
    $monthConfig=$config;
    $clock->set('2026-11-01T00:12:00+09:00');$monthController=new InteractionController($monthConfig,$queue,$clock,true,$approved,'operations',$diag);
    foreach(['2026-10'=>200,'2026-11'=>200,'2026-09'=>200]as$month=>$expected){
        $payload=json_decode($body,true,32,JSON_THROW_ON_ERROR);$payload['id']=(string)(900000000000005000+(int)substr($month,5));$payload['type']=3;$payload['data']=['component_type'=>2,'custom_id'=>'radio:card:'.$month];
        $monthBody=json_encode($payload,JSON_THROW_ON_ERROR);$timestamp=(string)$clock->now()->getTimestamp();
        $reply=$monthController->handle('POST',['x-signature-timestamp'=>$timestamp,'x-signature-ed25519'=>bin2hex(sodium_crypto_sign_detached($timestamp.$monthBody,sodium_crypto_sign_secretkey($keys)))],$monthBody);
        $assert($reply->status===$expected,'same production card rules accept current and historical months');
    }

    foreach(['success','member403','member429','member_exception','voice_timeout','room403','warning403','extra','race']as$mode){
        $root=$temp.'/'.$mode;mkdir($root,0700);mkdir($root.'/private',0700);
        $clock=new FrozenClock('2026-10-20T11:42:00+09:00');$inner=new RuntimeFixtureHttp();$inner->voices=array_fill_keys($approved,$voice);
        $http=new FailureHttp($inner,$mode);$config=runtimeFixtureConfig($root,'best_effort');$config=array_replace($config,[
            'participant_user_ids'=>$approved,'schedule'=>['daily_times'=>[],'one_off_times'=>[['at'=>'2026-10-20 11:37','slot_id'=>'morning']]],
            'live_automation_enabled'=>false,'roles_enabled'=>false,'achievement_notifications_enabled'=>false,'role_ids'=>[],'diagnostics_enabled'=>true]);
        $runtime=new OperationsRuntime($http,fn()=>$inner->transport(),$clock);$context=$runtime->build($config,static fn()=>true);
        $receipt=$context->service->openAttendance($approved[1],'900000000000001001');
        $assert($receipt['ok']&&$receipt['code']==='receipt_opened','member2 can open receipt through real service with fake REST');
        foreach($approved as$index=>$user){$result=$context->service->attend($receipt['session_id'],$user,(string)(900000000000001100+$index));$assert($result['ok'],'all approved users attend');}
        if($mode==='warning403'){$http->active=true;}
        $clock->advance(540);$runtime->cycle($context,static fn()=>true);
        $http->active=true;
        if($mode==='extra'){$inner->voices['900000000000000199']=$voice;}
        if($mode==='race'){$inner->raceBeforeGuard=true;}
        $clock->advance(60);$cycle=$runtime->cycle($context,static fn()=>true);
        $q=$context->db->pdo()->query('SELECT status FROM sessions');$state=$q->fetchColumn();
        if($mode==='success'){
            $assert($state==='closed'&&count($inner->memberPatches())===3,'success closes and disconnects exact three');
            $assert(in_array('closed',$cycle['sessions'],true),'inner closure success is explicit');
            $failedSink=new class implements FollowupSink{public function send(string $id,string $token,array $result,?string $png):bool{return false;}};
            foreach(['card','status']as$index=>$action){
                $id=(string)(900000000000004000+$index);
                $context->queue->enqueue(['interaction_id'=>$id,'action'=>$action,'target'=>$action==='card'?'2026-10':'self','user_id'=>$approved[2],
                    'display_name'=>'CANARY_SECRET_NAME','request_hash'=>str_repeat('a',64),'scope_mode'=>'operations'],'CANARY_SECRET_WORKER_TOKEN',$clock->now()->getTimestamp());
                $worker=new InteractionWorker($context->queue,$context->service,static function(){throw new RuntimeException('CANARY_SECRET_RENDERER');},
                    $failedSink,$clock,null,'operations',static fn()=>['ok'=>true],$context->diagnostics);
                $failed=$worker->runOnce($id);
                $assert($failed['state']==='retry'&&$failed['code']===($action==='card'?'worker_retry':'validation_followup_retry'),'renderer/followup failure retained and bounded');
            }
            $http->mode='followup403';$id='900000000000004099';
            $context->queue->enqueue(['interaction_id'=>$id,'action'=>'status','target'=>'self','user_id'=>$approved[1],
                'display_name'=>'CANARY_SECRET_NAME','request_hash'=>str_repeat('b',64),'scope_mode'=>'operations'],'CANARY_SECRET_FOLLOWUP',$clock->now()->getTimestamp());
            $failed=$context->worker->runOnce($id);$assert($failed['state']==='retry','real followup adapter handles fictional HTTP403 without new public post');
        }else{
            $assert($state!=='closed'&&!in_array('closed',$cycle['sessions'],true),'failure never claims closed: '.$mode);
            $assert(count($inner->memberPatches())===($mode==='race'?2:0),'failure affects no unapproved/moved member: '.$mode);
            $assert(($cycle['session_results'][0]['ok']??true)===false,'inner failure retained despite healthy outer cycle: '.$mode);
        }
        $log=file_get_contents($root.'/private/operations-diagnostic.safe.jsonl');$rows=$events($log);
        file_put_contents($package.'/diagnostics-last-case.safe.jsonl',$log);
        $assert($has($rows,'session'),'inner session result logged');
        if($mode==='success'){
            $assert($has($rows,'worker_result','exception')&&$has($rows,'followup','validation_followup_retry'),'card generation/followup failures observable');
            $assert(count(array_filter($rows,static fn($row)=>$row['stage']==='followup'&&($row['status']??null)===403))>0,'followup HTTP status is observable without webhook URL/token');
        }
        if($mode==='member403'){$assert($has($rows,'rest','forbidden')&&$has($rows,'member_pending','disconnect_pending'),'403 and member-pending separately observable');$before=count($http->attempts);$runtime->cycle($context,static fn()=>true);$assert(count($http->attempts)>$before,'definite rejection remains retryable after fresh safety checks');}
        if($mode==='member429'){$assert($has($rows,'rest','rate_limited'),'429 status retained');}
        if($mode==='member_exception'){$assert($has($rows,'rest_exception','exception')&&!str_contains($log,'CANARY_SECRET'),'transport exception type recorded, text excluded');$before=count($http->attempts);$runtime->cycle($context,static fn()=>true);$assert(count($http->attempts)===$before,'uncertain transport attempt is never blindly resent');}
        if($mode==='voice_timeout'){$assert(count(array_filter($rows,static fn($r)=>($r['transport']??null)==='transport_timeout'))>0,'transport timeout identified');}
        if($mode==='room403'){$assert($has($rows,'session','room_close_pending')&&$http->attempts===[],'room close fails before member PATCH');}
        if($mode==='warning403'){$assert($has($rows,'session','warning_pending')&&$http->attempts===[],'failed warning never shortens grace into disconnect');}
        if($mode==='extra'){$assert($has($rows,'roster','roster_scope_rejected')&&$http->attempts===[],'fourth member fails closed');}
        if($mode==='race'){
            $moved=array_keys(array_filter($inner->voices,static fn($channel)=>$channel==='900000000000000199'));
            $assert(count($moved)===1&&!$inner->wrongSourceApplied&&count(array_filter($inner->memberPatches(),static fn($request)=>str_ends_with($request->url(),'/'.$moved[0])))===0,'user moved elsewhere is never disconnected');
        }
        $assert(!str_contains($log,'fictional_runtime_bot_token')&&!str_contains($log,$approved[0])&&!str_contains($log,'https://'),'runtime log contains no token, raw participant or URL');
        $allLogs.=$log;$scenarios[]=['case'=>$mode,'inner_codes'=>$cycle['sessions'],'closed'=>$state==='closed'];
        $context->roster->close();
        if($mode==='room403'){
            $clock->advance(6);
            mkdir($root.'/public',0700);$path=$root.'/config.php';file_put_contents($path,"<?php\nreturn ".var_export($config,true).";\n");
            $out='';$cli=new OperationsCli($runtime);$exit=$cli->run(['--run','--duration=1','--config='.$path,'--public-root='.$root.'/public'],static function($line)use(&$out){$out.=$line;});
            $summary=json_decode($out,true,32,JSON_THROW_ON_ERROR);
            file_put_contents($package.'/cli-failure-observed.safe.json',json_encode($summary,JSON_PRETTY_PRINT|JSON_THROW_ON_ERROR)."\n");
            $assert($exit===0&&in_array($summary['code'],['worker_complete','worker_idle'],true)&&$summary['session_failures']>0&&in_array('room_close_pending',$summary['session_codes'],true),'CLI shows inner closure failure alongside outer worker completion');
        }
        unset($context,$worker,$q);
    }
    // A late schema failure exercises the real CLI catch after runtime construction, not a stubbed CLI.
    $root=$temp.'/cli-schema';mkdir($root,0700);mkdir($root.'/private',0700);mkdir($root.'/public',0700);
    $clock=new FrozenClock('2026-10-20T12:00:00+09:00');$inner=new RuntimeFixtureHttp();$http=new FailureHttp($inner,'cli_schema');
    $config=array_replace(runtimeFixtureConfig($root),['diagnostics_enabled'=>true,'schedule'=>['daily_times'=>[],'one_off_times'=>[['at'=>'2026-10-20 14:00','slot_id'=>'morning']]]]);
    $path=$root.'/config.php';file_put_contents($path,"<?php\nreturn ".var_export($config,true).";\n");
    $http->onIdentity=static function()use($config):void{$fault=new PDO('sqlite:'.$config['private_db_path']);$fault->exec('ALTER TABLE sessions RENAME TO held_sessions');};
    $runtime=new OperationsRuntime($http,fn()=>$inner->transport(),$clock);$cli=new OperationsCli($runtime);$out='';
    $exit=$cli->run(['--once','--config='.$path,'--public-root='.$root.'/public'],static function($line)use(&$out){$out.=$line;});
    $observed=json_decode($out,true,32,JSON_THROW_ON_ERROR);
    $assert($exit===1&&$observed===['ok'=>false,'code'=>'operations_unavailable'],'CLI reports late schema exception as fixed unavailable exit1');
    $log=file_get_contents($root.'/private/operations-diagnostic.safe.jsonl');$rows=$events($log);
    $assert(count(array_filter($rows,static fn($r)=>$r['stage']==='cycle'&&($r['code']??null)==='exception'&&($r['exception']??null)==='PDOException'))===1,'CLI late exception retains one private PDOException category');
    $assert(count($inner->calls)===1&&$inner->connections===0&&$inner->memberPatches()===[]&&$inner->messages===[],'CLI fault stops before room/member/message/Gateway effects');
    $assert(!str_contains($out,'PDOException')&&!str_contains($out,'sessions')&&!str_contains($log,'CANARY_SECRET')&&!str_contains($log,$config['bot_token'])&&!str_contains($log,$root),'CLI public/private outputs exclude raw exception, token and filesystem path');
    $repair=new PDO('sqlite:'.$config['private_db_path']);$repair->exec('ALTER TABLE held_sessions RENAME TO sessions');unset($repair);
    $out='';$recoveredExit=$cli->run(['--once','--config='.$path,'--public-root='.$root.'/public'],static function($line)use(&$out){$out.=$line;});
    $recovered=json_decode($out,true,32,JSON_THROW_ON_ERROR);
    $assert($recoveredExit===0&&($recovered['ok']??false)&&$recovered['code']==='operations_cycle','same CLI/runtime recovers after local schema restoration');
    $assert($inner->connections===0&&$inner->memberPatches()===[]&&$inner->messages===[],'recovered before-opening cycle has no external effects');
    file_put_contents($package.'/cli-exception.safe.json',json_encode(['offline_only'=>true,'fault'=>'sessions_renamed_after_runtime_build','exit'=>$exit,'public'=>$observed,'private_exception'=>'PDOException','recovered_exit'=>$recoveredExit,'recovered_code'=>$recovered['code'],'effect_requests'=>0],JSON_PRETTY_PRINT|JSON_THROW_ON_ERROR)."\n");
    $allLogs.=$log;$scenarios[]=['case'=>'cli_schema_exception','code'=>$observed['code'],'recovered'=>$recovered['ok']];

    // Ownership loss during followup failure must not reset a replacement claim.
    $root=$temp.'/claim-loss';mkdir($root,0700);mkdir($root.'/private',0700);$clock=new FrozenClock('2026-10-20T12:00:00+09:00');
    $inner=new RuntimeFixtureHttp();$runtime=new OperationsRuntime($inner,fn()=>$inner->transport(),$clock);$config=runtimeFixtureConfig($root);$context=$runtime->build($config,static fn()=>true);
    $id='900000000000006001';$context->queue->enqueue(['interaction_id'=>$id,'action'=>'status','target'=>'self','user_id'=>$approved[0],'display_name'=>'CANARY_SECRET_NAME','request_hash'=>str_repeat('c',64),'scope_mode'=>'operations'],'CANARY_SECRET_TOKEN',$clock->now()->getTimestamp());
    $replacement='replacement-lease';$sink=new class($context->db->pdo(),$replacement) implements FollowupSink{
        public function __construct(private PDO $pdo,private string $replacement){}
        public function send(string $id,string $token,array $result,?string $png):bool{$this->pdo->prepare("UPDATE interaction_jobs SET lease_id=?,attempts=attempts+1 WHERE interaction_id=?")->execute([$this->replacement,$id]);return false;}
    };
    $lossLog='';$lossDiag=SafeDiagnostics::fromConfig(['diagnostics_enabled'=>true],static function($line)use(&$lossLog){$lossLog.=$line;});
    $worker=new InteractionWorker($context->queue,$context->service,static function(){throw new RuntimeException('renderer must not be called');},$sink,$clock,null,'operations',static fn()=>['ok'=>true],$lossDiag);
    $lost=$worker->runOnce($id);$row=$context->db->pdo()->query('SELECT state,lease_id,attempts,token_ciphertext,result_code FROM interaction_jobs')->fetch(PDO::FETCH_ASSOC);
    $assert($lost['state']==='lost'&&$lost['code']==='worker_claim_lost','failed followup reports claim loss rather than a false retry');
    $assert($row['state']==='processing'&&$row['lease_id']===$replacement&&(int)$row['attempts']===2&&$row['token_ciphertext']!==''&&$row['result_code']===null,'stale retry preserves replacement ownership, token, attempts and result');
    $assert($has($events($lossLog),'worker_result','worker_claim_lost')&&!str_contains($lossLog,'CANARY_SECRET'),'claim loss diagnostic is fixed and contains no secret');
    // Even a reused lease identifier must not let an old attempt update a newer attempt.
    $stale=['interaction_id'=>$id,'lease_id'=>$replacement,'attempts'=>1];
    $assert($context->queue->retry($stale,'worker_retry',$clock->now()->getTimestamp())===null,'attempt CAS rejects stale attempt with same lease identifier');
    $assert($context->db->pdo()->query('SELECT state FROM interaction_jobs')->fetchColumn()==='processing','attempt CAS rejection leaves current claim intact');
    $allLogs.=$lossLog;$scenarios[]=['case'=>'retry_claim_loss','code'=>$lost['code'],'replacement_preserved'=>true];$context->roster->close();

    $summary=['offline_only'=>true,'assertions'=>$checks,'cases'=>$scenarios,'real_api_calls'=>0,'server_actions'=>0,'secret_canaries_absent'=>!str_contains($allLogs,'CANARY_SECRET')];
    file_put_contents($package.'/diagnostics-failures.safe.json',json_encode($summary,JSON_PRETTY_PRINT|JSON_THROW_ON_ERROR)."\n");
    echo json_encode(['offline_only'=>true,'assertions'=>$checks,'cases'=>count($scenarios),'real_api_calls'=>0,'secret_canaries_absent'=>$summary['secret_canaries_absent']],JSON_PRETTY_PRINT|JSON_THROW_ON_ERROR),"\n";
}finally{
    unset($db,$queue,$locker,$controller,$monthController,$context,$worker,$q,$runtime,$cli,$delivery,$sink);gc_collect_cycles();
    $resolved=str_replace('\\','/',realpath($temp)?:'');$root=str_replace('\\','/',realpath(sys_get_temp_dir())?:'');
    if($resolved!==$temp||!str_starts_with($resolved,$root.'/radio-local-repair-')){throw new RuntimeException('cleanup_boundary');}
    $files=new RecursiveIteratorIterator(new RecursiveDirectoryIterator($temp,FilesystemIterator::SKIP_DOTS),RecursiveIteratorIterator::CHILD_FIRST);
    foreach($files as$file){if($file->isDir()){rmdir($file->getPathname());}else{unlink($file->getPathname());}}rmdir($temp);
}
