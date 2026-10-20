<?php
declare(strict_types=1);
require_once __DIR__.'/artifact_directory.php';
require dirname(__DIR__).'/bootstrap.php';

use RadioStamp\Cards\CardRenderer;
use RadioStamp\Core\{Database,FrozenClock,RadioService,ParticipationDays};
use RadioStamp\Discord\{DiscordRestClient,HttpAdapter,HttpRequest,HttpResponse,MockGateway};
use RadioStamp\Http\{InteractionController,InteractionQueue,InteractionWorker,DiscordRestFollowupSink,FollowupSink};
use RadioStamp\Security\TokenCipher;

/** Every request is captured in this process. This adapter has no network implementation. */
final class DownloadFixtureHttp implements HttpAdapter
{
    public array $requests=[];
    public array $responses=[];
    public function exchange(HttpRequest $request):HttpResponse
    {
        $this->requests[]=$request;$response=array_shift($this->responses);
        if($response instanceof Throwable){throw $response;}
        return $response instanceof HttpResponse?$response:new HttpResponse(200,[],'{}');
    }
}

$checks=0;$scenarios=[];$sequence=0;
$same=static function(mixed $expected,mixed $actual,string $why)use(&$checks):void{++$checks;if($expected!==$actual){throw new RuntimeException('Download: '.$why);}};
$assert=static function(bool $ok,string $why)use($same):void{$same(true,$ok,$why);};
$earned='900000000000000101';$unearned='900000000000000102';$outsider='900000000000000199';
$root=str_replace('\\','/',sys_get_temp_dir().'/radio-card-download-'.bin2hex(random_bytes(8)));mkdir($root,0700);
$keys=sodium_crypto_sign_seed_keypair(str_repeat("\x31",32));$secret=sodium_crypto_sign_secretkey($keys);
$clock=new FrozenClock('2026-10-20T07:00:00+09:00');$db=new Database($root.'/private.sqlite');$pdo=$db->pdo();
$config=['mode'=>'mock','admin_validation_enabled'=>true,'application_id'=>DiscordRestClient::APPLICATION_ID,'guild_id'=>DiscordRestClient::GUILD_ID,
    'public_key_hex'=>bin2hex(sodium_crypto_sign_publickey($keys)),'token_encryption_key_hex'=>str_repeat('52',32),'private_db_path'=>$root.'/private.sqlite',
    'admin_user_ids'=>[$earned,$unearned],'voice_channel_id'=>DiscordRestClient::VOICE_CHANNEL_ID,'text_channel_id'=>DiscordRestClient::TEXT_CHANNEL_ID,
    'records_channel_id'=>DiscordRestClient::RECORDS_CHANNEL_ID,'timezone'=>'Asia/Tokyo','member_actions_enabled'=>false,'role_ids'=>[],
    'live_automation_enabled'=>false,'achievement_notifications_enabled'=>false,'participant_commands_enabled'=>false];
$gateway=new MockGateway($clock);$service=new RadioService($db,$gateway,$clock,$config);$queue=new InteractionQueue($pdo,new TokenCipher($config['token_encryption_key_hex']));
$controller=new InteractionController($config,$queue,$clock);
$http=new DownloadFixtureHttp();$client=new DiscordRestClient($http,DiscordRestClient::APPLICATION_ID,'FICTIONAL_DOWNLOAD_BOT_TOKEN',['enabled'=>true]);$sink=new DiscordRestFollowupSink($client);
$fonts=['/usr/share/fonts/ipa-gothic/ipag.ttf','/usr/share/fonts/ipa-pgothic/ipagp.ttf','/usr/share/fonts/ja/TrueType/kochi-gothic-subst.ttf','/usr/share/fonts/vlgothic/VL-Gothic-Regular.ttf','/usr/share/fonts/opentype/noto/NotoSansCJK-Regular.ttc','/usr/share/fonts/ipaexg/ipaexg.ttf','C:/Windows/Fonts/BIZ-UDGothicR.ttc','C:/Windows/Fonts/msgothic.ttc'];
$renderer=new CardRenderer($fonts);$worker=new InteractionWorker($queue,$service,$renderer,$sink,$clock);
$stage=getenv('RADIO_STAMP_TEST_ARTIFACT_DIR');
if(!is_dir($stage)&&!mkdir($stage,0700,true)&&!is_dir($stage)){throw new RuntimeException('download_artifact_directory_unavailable');}

$payload=static function(string $action,string $user)use(&$sequence,$config):array{
    $button=str_starts_with($action,'radio:');
    return ['id'=>(string)(900000000000080000+ ++$sequence),'application_id'=>$config['application_id'],'guild_id'=>$config['guild_id'],'channel_id'=>$config['voice_channel_id'],
        'token'=>'FICTIONAL_DOWNLOAD_CALLBACK_'.$sequence,'type'=>$button?3:2,'member'=>['nick'=>'架空の取得試験','user'=>['id'=>$user,'username'=>'fictional_download_user']],
        'data'=>$button?['component_type'=>2,'custom_id'=>$action]:['type'=>1,'name'=>$action]];
};
$send=static function(array $data,?InteractionController $ingress=null)use($controller,$secret,$clock){
    $body=json_encode($data,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);$stamp=(string)$clock->now()->getTimestamp();
    return ($ingress??$controller)->handle('POST',['X-Signature-Timestamp'=>$stamp,'X-Signature-Ed25519'=>bin2hex(sodium_crypto_sign_detached($stamp.$body,$secret))],$body);
};
$read=static function(string $id)use($pdo):array{$q=$pdo->prepare('SELECT * FROM interaction_jobs WHERE interaction_id=?');$q->execute([$id]);return $q->fetch();};
$reply=static function(HttpRequest $request):array{
    $body=$request->body();$png=null;
    if(str_starts_with($body,'--RadioStamp')){
        if(!preg_match('/name="payload_json"\r\nContent-Type: application\/json\r\n\r\n(.*?)\r\n--RadioStamp/s',$body,$match)){throw new RuntimeException('download_multipart_json_invalid');}
        $json=$match[1];$boundary=substr($body,2,strpos($body,"\r\n")-2);$start=strpos($body,"\x89PNG\r\n\x1a\n");
        if($start!==false){$png=substr($body,$start,-strlen("\r\n--".$boundary."--\r\n"));}
    }else{$json=$body;}
    return ['payload'=>json_decode($json,true,32,JSON_THROW_ON_ERROR),'png'=>$png];
};
$privateReply=static function(HttpRequest $request,array $data)use($same,$assert,$reply):array{
    $same('PATCH',$request->method(),'original is edited, no new post');
    $same('https://discord.com/api/v10/webhooks/'.DiscordRestClient::APPLICATION_ID.'/'.$data['token'].'/messages/@original',$request->url(),'exact matching callback original');
    $same(0,count(array_filter($request->headers(),static fn(string $h):bool=>str_starts_with($h,'Authorization:'))),'Bot credentials absent on callback');
    $r=$reply($request);$same(['parse'=>[],'users'=>[],'roles'=>[],'replied_user'=>false],$r['payload']['allowed_mentions'],'mentions disabled');
    $assert(!str_contains($r['payload']['content'],'FICTIONAL_'),'fixed message excludes test credentials');return $r;
};
$snapshot=static function()use($pdo):array{$state=[];foreach(['sessions','participants','attendance','memorial_awards','core_interactions','achievement_notices','day_achievement_notices','core_effects']as$table){$state[$table]=$pdo->query('SELECT * FROM '.$table.' ORDER BY rowid')->fetchAll();}return $state;};

try{
    // Private, coherent history fixture: 365 actual JST dates; no fake award grants are needed.
    $db->transaction(static function(PDO $p)use($earned,$unearned):void{
        $p->prepare('INSERT INTO participants(user_id,total_count) VALUES(?,?)')->execute([$earned,365]);$p->prepare('INSERT INTO participants(user_id,total_count) VALUES(?,?)')->execute([$unearned,0]);
        $session=$p->prepare("INSERT INTO sessions(id,scheduled_at,count_date,opens_at,unstarted_closes_at,status,started_at,closes_at,closed_at,slot_id,stamp) VALUES(?,?,?,?,?,'closed',?,?,?,?,?)");
        $attendance=$p->prepare('INSERT INTO attendance(session_id,user_id,count_date,attended_at,ordinal,stamp) VALUES(?,?,?,?,?,?)');
        for($i=1;$i<=365;$i++){$at=(new DateTimeImmutable('2025-10-21T07:00:00+09:00'))->modify('+'.($i-1).' days');$time=$at->getTimestamp();$date=$at->format('Y-m-d');$id=$date.'_0700';
            $session->execute([$id,$time,$date,$time-1800,$time+1800,$time,$time+900,$time+900,'morning','LED']);$attendance->execute([$id,$earned,$date,$time,$i,'LED']);}
        // Preserve an old, retired award row as history; it does not authorize new day-one cards.
        $p->prepare('INSERT INTO memorial_awards(user_id,milestone,reached_at) VALUES(?,?,?)')->execute([$earned,1,1761000000]);
    });
    $before=$snapshot();$same(ParticipationDays::MILESTONES,$service->cardData($earned,'架空の取得試験','2026-10')['awards'],'all six are derived from actual JST attendance');
    $monthly=$payload('radio-card',$earned);$same(['type'=>5,'data'=>['flags'=>64]],$send($monthly)->body,'signed monthly command defers privately');$same('card_ready',$worker->runOnce($monthly['id'])['code'],'monthly worker success');
    $r=$privateReply($http->requests[array_key_last($http->requests)],$monthly);$same(2,count($r['payload']['components']),'six buttons span two rows');
    $buttons=array_merge(...array_column($r['payload']['components'],'components'));
    $same(array_map(static fn(int $n):string=>'radio:memorial:'.$n,ParticipationDays::MILESTONES),array_column($buttons,'custom_id'),'exact current milestones');
    $assert(str_contains($r['payload']['content'],'保存')&&str_contains($r['payload']['content'],'/radio-card'),'monthly response explains saving and re-request');
    $hashes=[];
    foreach(ParticipationDays::MILESTONES as$n){
        $data=$payload('radio:memorial:'.$n,$earned);$same(['type'=>5,'data'=>['flags'=>64]],$send($data)->body,'memorial defers privately');$same('memorial_ready',$worker->runOnce($data['id'])['code'],'earned memorial generated');
        $r=$privateReply($http->requests[array_key_last($http->requests)],$data);$assert(is_string($r['png']),'PNG attachment present');$info=getimagesizefromstring($r['png']);$same([1200,800,IMAGETYPE_PNG],array_slice($info,0,3),'complete PNG decodes');$assert(strlen($r['png'])<=1048576,'download fits existing transport cap');
        $same([['id'=>0,'filename'=>'card.png','description'=>'ラジオ体操スタンプカード']],$r['payload']['attachments'],'explicit named PNG attachment');
        $assert(str_contains($r['payload']['content'],'保存')&&str_contains($r['payload']['content'],'ダウンロード')&&str_contains($r['payload']['content'],'/radio-card')&&str_contains($r['payload']['content'],'再取得'),'private reply contains save/re-acquire steps');
        $path=$stage.'/download-day'.$n.'.png';$same(strlen($r['png']),file_put_contents($path,$r['png']),'attachment can be saved as complete local file');$same($r['png'],file_get_contents($path),'saved file preserves bytes');$hashes[$n]=hash('sha256',$r['png']);
        $same('',$read($data['id'])['token_ciphertext'],'completion erases callback');
    }
    $same(6,count(array_unique($hashes)),'six missing-art placeholders are distinct downloadable cards');
    $scenarios['six_milestones']=['placeholder_downloadable'=>true,'milestones'=>ParticipationDays::MILESTONES,'png_sha256'=>$hashes,'private_ack_flags'=>64];

    // Additional target/user fields cannot select someone else's history.
    $forged=$payload('radio:memorial:365',$unearned);$forged['user_id']=$earned;$forged['target']=$earned;$forged['data']['user_id']=$earned;$forged['data']['target']=$earned;
    $same(200,$send($forged)->status,'signed actor accepted despite irrelevant fields');$same($unearned,$read($forged['id'])['user_id'],'only signed member actor is stored');$same('memorial_locked',$worker->runOnce($forged['id'])['code'],'unearned actor cannot borrow earned identity');
    $r=$privateReply($http->requests[array_key_last($http->requests)],$forged);$same(null,$r['png'],'locked reply has no PNG');$same([],$r['payload']['attachments'],'locked reply clears attachments');
    foreach(['radio:memorial:365:'.$earned,'radio:memorial:1','radio:memorial:../365']as$bad){$data=$payload($bad,$unearned);$same(400,$send($data)->status,'target injection or removed milestone rejected');}
    $data=$payload('radio:memorial:365',$outsider);$same(403,$send($data)->status,'unauthorized actor rejected before queue');
    $command=$payload('radio-card',$unearned);$command['data']['options']=[['type'=>6,'name'=>'user','value'=>$earned]];$same(400,$send($command)->status,'other-user slash option not supported');
    $scenarios['ownership']=['unearned_locked'=>true,'extra_user_id_ignored'=>true,'cross_user_custom_id_rejected'=>true,'unauthorized_actor_rejected'=>true];

    $again=$payload('radio:memorial:365',$earned);$same(200,$send($again)->status,'new interaction can reacquire');$same('memorial_ready',$worker->runOnce($again['id'])['code'],'re-acquire succeeds');$r=$privateReply($http->requests[array_key_last($http->requests)],$again);$same($hashes[365],hash('sha256',$r['png']),'same history and material reacquires same PNG');
    $calls=count($http->requests);$same(200,$send($again)->status,'completed duplicate gets valid ACK');$same(null,$worker->runOnce($again['id']),'completed ID is not sent again');$same($calls,count($http->requests),'completed replay has no followup');
    $expired=$payload('radio:memorial:365',$earned);$same(200,$send($expired)->status,'expiry fixture queued');$clock->advance(900);$same(null,$worker->runOnce($expired['id']),'expired callback never renders or sends');$same($calls,count($http->requests),'expiry does not send to another channel');$row=$read($expired['id']);$same('expired',$row['state'],'expired state explicit');$same('',$row['token_ciphertext'],'expired callback erased');
    $fresh=$payload('radio:memorial:365',$earned);$same(200,$send($fresh)->status,'new command accepted after expiry');$same('memorial_ready',$worker->runOnce($fresh['id'])['code'],'fresh original obtains a new attachment');$r=$privateReply($http->requests[array_key_last($http->requests)],$fresh);$same($hashes[365],hash('sha256',$r['png']),'expired original does not consume qualification');
    $scenarios['reacquire_and_expiry']=['duplicate_no_new_send'=>true,'expired_callback_seconds'=>900,'expired_callback_no_send'=>true,'fresh_interaction_new_attachment'=>true,'real_cdn_expiry_unverified'=>true];

    // Transport uncertainty retries the same image PATCH, never an alternative failure message.
    $uncertain=$payload('radio:memorial:10',$earned);$send($uncertain);$http->responses=[new HttpResponse(0,[],'','transport_timeout'),new HttpResponse(200,[],'{}')];$start=count($http->requests);
    $same('retry',$worker->runOnce($uncertain['id'])['state'],'uncertain delivery is not successful');$clock->advance(2);$same('memorial_ready',$worker->runOnce($uncertain['id'])['code'],'same original image PATCH can recover');$same($start+2,count($http->requests),'exactly two PATCH attempts');
    foreach(array_slice($http->requests,$start)as$request){$r=$privateReply($request,$uncertain);$assert(is_string($r['png']),'uncertain delivery retry retains image');$assert(!str_contains($r['payload']['content'],'準備できません'),'transport failure not relabeled as rendering failure');}
    $scenarios['uncertain_delivery']=['same_original_only'=>true,'attempts'=>2,'no_failure_message_replacement'=>true];

    // A previous image PATCH might have succeeded even if its result was unavailable.
    // Later local faults must preserve that history rather than clearing its attachment.
    $priorDelivery=[];
    foreach(['false','throw']as$mode){
        $data=$payload('radio:memorial:10',$earned);$same(200,$send($data)->status,'prior-delivery fixture privately queued');
        $renderCalls=0;$start=count($http->requests);
        $laterFault=static function()use(&$renderCalls,$renderer):CardRenderer{if(++$renderCalls===1){return $renderer;}throw new RuntimeException('DOWNLOAD_CANARY_LATER_LOCAL_FAULT');};
        $historySink=$mode==='throw'?new class($sink)implements FollowupSink{
            public function __construct(private readonly FollowupSink $inner){}
            public function send(string $id,string $token,array $result,?string $png):bool{
                $this->inner->send($id,$token,$result,$png);
                // Simulate an accepted edit whose completion was unavailable to the worker.
                throw new RuntimeException('DOWNLOAD_CANARY_AFTER_ACCEPTED_EDIT');
            }
        }:$sink;
        $http->responses=$mode==='false'?[new HttpResponse(0,[],'','transport_timeout')]:[];
        $historyWorker=new InteractionWorker($queue,$service,$laterFault,$historySink,$clock);
        for($attempt=1;$attempt<=5;$attempt++){
            $result=$historyWorker->runOnce($data['id']);$row=$read($data['id']);
            $same($attempt<5?'retry':'failed',$result['state'],'prior delivery followed by local faults remains bounded');
            $same($attempt<5?'mock_followup_retry':'worker_failed',$result['code'],'prior transport uncertainty remains explicit');
            $same('mock_followup_retry',$row['result_code'],'local fault does not erase previous transport history');
            $same($start+1,count($http->requests),'only first image PATCH is attempted; no replacement failure notice');
            if($attempt<5){$clock->advance(2**$attempt);}
        }
        $same(5,$renderCalls,'prior delivery has at most five total worker attempts');
        $same('',$row['token_ciphertext'],'terminal delivery history erases callback');
        $same(null,$historyWorker->runOnce($data['id']),'terminal prior delivery is not sent again');
        $r=$privateReply($http->requests[$start],$data);$assert(is_string($r['png']),'only attempted response contains the original PNG');
        $same($hashes[10],hash('sha256',$r['png']),'original image bytes are preserved');
        $assert(!str_contains($r['payload']['content'],'画像を準備できません'),'local failure notice never replaces possibly delivered image');
        $priorDelivery[$mode]=['state'=>$row['state'],'result_code'=>$row['result_code'],'attempts'=>5,'image_patch_attempts'=>1,'replacement_failure_notices'=>0];
    }
    $scenarios['prior_delivery_followed_by_render_failure']=$priorDelivery;

    $throwingSink=new class implements FollowupSink{
        public array $results=[];
        public function send(string $id,string $token,array $result,?string $png):bool{$this->results[]=['result'=>$result,'has_png'=>$png!==null];throw new RuntimeException('DOWNLOAD_CANARY_SINK_EXCEPTION');}
    };
    $throwingWorker=new InteractionWorker($queue,$service,$renderer,$throwingSink,$clock);$transportThrow=$payload('radio:memorial:10',$earned);$send($transportThrow);
    $same('retry',$throwingWorker->runOnce($transportThrow['id'])['state'],'sink exception remains retryable without image-error fallback');
    $same([['result'=>['ok'=>true,'code'=>'memorial_ready'],'has_png'=>true]],$throwingSink->results,'throwing sink sees only original successful image result');
    $clock->advance(2);$same('memorial_ready',$worker->runOnce($transportThrow['id'])['code'],'sink exception can recover with same original image');
    $scenarios['sink_exception']=['no_image_error_fallback'=>true,'recovered_with_image'=>true];

    // Operations queue scope exercises the production scope/preflight branch with a local mock service and fake REST.
    $operationsController=new InteractionController($config,$queue,$clock,false,[$earned,$unearned],'operations');$preflightCalls=0;
    $operationsWorker=new InteractionWorker($queue,$service,$renderer,$sink,$clock,null,'operations',static function()use(&$preflightCalls):array{++$preflightCalls;return ['ok'=>true];});
    $ops=$payload('radio:memorial:30',$earned);$same(200,$send($ops,$operationsController)->status,'operations queue scope accepts signed owner');$same('operations',$read($ops['id'])['scope_mode'],'scope stored explicitly');$same('memorial_ready',$operationsWorker->runOnce($ops['id'])['code'],'operations branch delivers through same sink');$same(1,$preflightCalls,'operations preflight runs');$privateReply($http->requests[array_key_last($http->requests)],$ops);

    // Only pure local rendering failures become final notices, after all existing five attempts.
    $failureModes=['success','rejected','uncertain','throw'];$terminal=[];
    foreach($failureModes as$mode){
        $data=$payload('radio:memorial:10',$earned);$send($data);$renderCalls=0;
        $throwRenderer=static function()use(&$renderCalls):CardRenderer{++$renderCalls;throw new RuntimeException('DOWNLOAD_CANARY_SECRET /private/material.png');};
        $failing=new InteractionWorker($queue,$service,$throwRenderer,$sink,$clock);$initialHttp=count($http->requests);
        for($attempt=1;$attempt<=5;$attempt++){
            if($attempt===5){$http->responses=match($mode){'rejected'=>[new HttpResponse(403,[],'{}')],'uncertain'=>[new HttpResponse(0,[],'','transport_timeout')],'throw'=>[new RuntimeException('DOWNLOAD_CANARY_TRANSPORT')],default=>[]};}
            $result=$failing->runOnce($data['id']);
            if($attempt<5){$same('retry',$result['state'],'generation retains bounded retry');$same('worker_retry',$result['code'],'generation retry stays distinct from send failure');$same($initialHttp,count($http->requests),'no failure notice before last attempt');$clock->advance(2**$attempt);}
        }
        $same(5,$renderCalls,'exactly five local generation attempts');$same($initialHttp+1,count($http->requests),'one final private failure notice attempt');$r=$privateReply($http->requests[array_key_last($http->requests)],$data);
        $same(null,$r['png'],'final notice has no partial image');$same([],$r['payload']['attachments'],'final notice has no attachments');$assert(str_contains($r['payload']['content'],'画像を準備できません')&&str_contains($r['payload']['content'],'/radio-card')&&str_contains($r['payload']['content'],'獲得記録は残'),'fixed error explains retry and preserved qualification');$assert(!str_contains($r['payload']['content'],'DOWNLOAD_CANARY'),'local details excluded');
        $same($mode==='success'?'done':'failed',$result['state'],'only delivered final notice is complete');$same($mode==='success'?'card_render_failed':'worker_failed',$result['code'],'terminal outcome accurate');$row=$read($data['id']);$same($mode==='success'?'done':'failed',$row['state'],'terminal state persisted');$same('',$row['token_ciphertext'],'all terminal paths erase callback');$same(null,$failing->runOnce($data['id']),'terminal failure notice never repeats');
        $terminal[$mode]=['state'=>$row['state'],'render_attempts'=>$renderCalls,'notice_attempts'=>1,'notice_has_png'=>false];
    }
    $scenarios['terminal_rendering_failure']=$terminal;
    $monthlyError=$payload('radio-card',$earned);$send($monthlyError);$monthlyFault=new InteractionWorker($queue,$service,static function():CardRenderer{throw new RuntimeException('DOWNLOAD_CANARY_MONTHLY');},$sink,$clock);
    for($attempt=1;$attempt<=5;$attempt++){$result=$monthlyFault->runOnce($monthlyError['id']);if($attempt<5){$clock->advance(2**$attempt);}}
    $same('card_render_failed',$result['code'],'monthly final error is explicit');$r=$privateReply($http->requests[array_key_last($http->requests)],$monthlyError);$same(null,$r['png'],'monthly error carries no PNG');$buttons=array_merge(...array_column($r['payload']['components'],'components'));$same(array_map(static fn(int $n):string=>'radio:memorial:'.$n,ParticipationDays::MILESTONES),array_column($buttons,'custom_id'),'earned benefit buttons survive monthly rendering failure');
    $scenarios['monthly_error_retains_earned_buttons']=true;
    $same($before,$snapshot(),'all downloads, failures and re-acquires preserve records and old awards');$same([],$gateway->actionLog,'download flow triggers no mock Discord business effects');
    foreach($http->requests as$request){$same('PATCH',$request->method(),'every download network boundary is an original edit');}
    $evidence=['status'=>'LOCAL_DOWNLOAD_FLOW_VERIFIED_NOT_DEPLOYED','assertions'=>$checks,'scenarios'=>$scenarios,'actual_network_calls'=>0,'actual_discord_operations'=>0,'deployed'=>false,'record_snapshot_unchanged'=>true,'desktop_or_mobile_save_ui_verified'=>false,'formal_art_created'=>false];
    file_put_contents($stage.'/card-download.safe.json',json_encode($evidence,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR)."\n");
    echo "Card download: $checks assertions passed (six private PNGs, ownership, re-acquire, expiry and final error replies; fake only).\n";
}finally{
    unset($worker,$operationsWorker,$failing,$throwingWorker,$monthlyFault,$historyWorker,$historySink,$service,$controller,$operationsController,$queue,$pdo,$db,$read,$snapshot,$send);gc_collect_cycles();
    $resolved=realpath($root);$temporary=realpath(sys_get_temp_dir());
    if($resolved===false||$temporary===false||!str_starts_with(str_replace('\\','/',$resolved),str_replace('\\','/',$temporary).'/radio-card-download-')){throw new RuntimeException('download_cleanup_boundary');}
    foreach(new DirectoryIterator($root)as$file){if(!$file->isDot()&&$file->isFile()&&!unlink($file->getPathname())){throw new RuntimeException('download_cleanup_failed');}}
    if(!rmdir($root)){throw new RuntimeException('download_cleanup_directory_failed');}
}
