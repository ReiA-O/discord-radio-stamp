<?php
declare(strict_types=1);
require_once __DIR__.'/artifact_directory.php';

/*
 * Offline-only regression for the participant attendance path.
 *
 * This file is intended to be copied into tests/ of a reviewed canonical
 * working copy.  It uses only the existing fictional HTTP adapter and a
 * temporary SQLite database.  It must never be run against a production
 * config, database, Discord token, or endpoint.
 */
require dirname(__DIR__).'/bootstrap.php';
require __DIR__.'/fixtures/OperationsRuntimeFixture.php';

use RadioStamp\Core\FrozenClock;
use RadioStamp\Discord\{DiscordRestClient,Gateway};
use RadioStamp\Http\{OperationsEndpoint,PostResponseDriver,Response};
use RadioStamp\Operations\OperationsRuntime;

$checks=0;
$same=static function(mixed $expected,mixed $actual,string $why)use(&$checks):void{
    ++$checks;
    if($expected!==$actual){
        throw new RuntimeException('Attend boundary: '.$why.' expected='.json_encode($expected,JSON_UNESCAPED_UNICODE).' actual='.json_encode($actual,JSON_UNESCAPED_UNICODE));
    }
};
$yes=static function(bool $actual,string $why)use($same):void{$same(true,$actual,$why);};

final class AttendBoundaryDriver implements PostResponseDriver
{
    public int $finished=0;
    public function available():bool{return true;}
    public function finish():bool{++$this->finished;return true;}
}

$root=sys_get_temp_dir().'/radio-attend-boundary-'.bin2hex(random_bytes(8));
if(!mkdir($root,0700,true)){throw new RuntimeException('temporary root unavailable');}
$fixtures=[];

$fixture=static function(string $name,bool $initialize)use($root,&$fixtures):array{
    $folder=$root.'/'.$name;
    foreach([$folder,$folder.'/private',$folder.'/public']as$directory){
        if(!mkdir($directory,0700)){throw new RuntimeException('temporary fixture unavailable');}
    }
    $config=runtimeFixtureConfig($folder,'manual',false);
    $config['diagnostics_enabled']=true;
    $configPath=$folder.'/private/config.php';
    file_put_contents($configPath,"<?php\nreturn ".var_export($config,true).";\n");
    $clock=new FrozenClock('2026-10-19T07:05:00+09:00');
    $http=new RuntimeFixtureHttp();
    $runtime=new OperationsRuntime($http,fn()=>$http->transport(),$clock);
    $context=$initialize?$runtime->build($config,static fn():bool=>true):null;
    $result=compact('folder','config','configPath','clock','http','runtime','context');
    $fixtures[]=$result;
    return $result;
};

$sequence=0;
$signed=static function(array $fixture,string $channel,string $kind='command')use(&$sequence):array{
    $user='800000000000001021';
    $id=(string)(900000000000012000+ ++$sequence);
    $data=[
        'id'=>$id,
        'application_id'=>DiscordRestClient::APPLICATION_ID,
        'guild_id'=>DiscordRestClient::GUILD_ID,
        'channel_id'=>$channel,
        'token'=>'fictional_attend_boundary_callback_000000',
        'type'=>$kind==='button'?3:2,
        'member'=>['nick'=>'検証参加者','user'=>['id'=>$user,'username'=>'fictional']],
        'data'=>$kind==='button'
            ?['component_type'=>2,'custom_id'=>'radio:attend:2026-10-19_0700']
            :['type'=>1,'name'=>'radio-attend'],
    ];
    $body=json_encode($data,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);
    $timestamp=(string)$fixture['clock']->now()->getTimestamp();
    $keys=sodium_crypto_sign_seed_keypair(str_repeat("\x17",32));
    $signature=bin2hex(sodium_crypto_sign_detached($timestamp.$body,sodium_crypto_sign_secretkey($keys)));
    return [[
        'REQUEST_METHOD'=>'POST',
        'HTTP_X_SIGNATURE_TIMESTAMP'=>$timestamp,
        'HTTP_X_SIGNATURE_ED25519'=>$signature,
    ],$body,$id];
};

$deliver=static function(array $fixture,string $channel,string $kind='command')use($signed):array{
    [$server,$body,$id]=$signed($fixture,$channel,$kind);
    $driver=new AttendBoundaryDriver();
    $delivery=(new OperationsEndpoint($fixture['folder'].'/public',$driver,$fixture['runtime']))
        ->prepare($server,$body,$fixture['configPath']);
    $emitted=null;
    $ok=$delivery->deliver($driver,static function(Response $response)use(&$emitted):void{$emitted=$response->body;});
    return [$delivery->response,$emitted,$ok,$driver,$id];
};

$events=static function(array $fixture):array{
    $path=$fixture['folder'].'/private/operations-diagnostic.safe.jsonl';
    if(!is_file($path)){return [];}
    return array_map(
        static fn(string $line):array=>json_decode($line,true,32,JSON_THROW_ON_ERROR),
        array_values(array_filter(explode("\n",file_get_contents($path))))
    );
};

// 1. A real signed /radio-attend in an allowed text channel must terminate at
//    immediate dispatch.  It proves ingress/signature/action/ACK without a DB,
//    worker, receipt post, callback, or attendance mutation.
$outside=$fixture('outside-vc',false);
[$response,$emitted,$ok,$driver]=$deliver($outside,DiscordRestClient::TEXT_CHANNEL_ID);
$same(200,$response->status,'outside-VC command returns Discord success');
$same(4,$response->body['type']??null,'outside-VC command is an immediate response');
$same(64,$response->body['data']['flags']??null,'outside-VC response is ephemeral');
$same(Gateway::ALLOWED_MENTIONS,$response->body['data']['allowed_mentions']??null,'outside-VC response suppresses mentions');
$yes(str_contains((string)($response->body['data']['content']??''),'体操VC'),'outside-VC response explains the required channel');
$same($response->body,$emitted,'exact prepared response is emitted');
$same(true,$ok,'immediate response delivery completes');
$same(1,$driver->finished,'immediate response is formally finished once');
$same(false,is_file($outside['config']['private_db_path']),'outside-VC path never opens or creates the application DB');
$same(0,count($outside['http']->calls),'outside-VC path performs no fictional Discord REST call');
$same(0,count($outside['http']->messages),'outside-VC path creates no shared receipt message');
$same(0,count($outside['http']->callbacks),'outside-VC path creates no follow-up');
$outsideEvents=$events($outside);
$outsideStages=array_column($outsideEvents,'stage');
foreach(['http_prepare','http_action','http_controller','ack_emit','ack_finish']as$stage){
    $yes(in_array($stage,$outsideStages,true),'outside-VC diagnostics include '.$stage);
}
foreach(['http_queue','ack_handoff','worker_claim','worker_result','followup','after_response']as$stage){
    $same(false,in_array($stage,$outsideStages,true),'outside-VC diagnostics stop before '.$stage);
}

// 2. From the voice channel's chat while the caller is not connected to voice,
//    the command must cross queue/ACK/worker/voice-read/follow-up, but it must
//    stop before session creation, shared posting, or attendance.
$absent=$fixture('voice-chat-not-connected',true);
[$response,$emitted,$ok,$driver,$absentId]=$deliver($absent,DiscordRestClient::VOICE_CHANNEL_ID);
$same(['type'=>5,'data'=>['flags'=>64]],$response->body,'not-connected command defers ephemerally');
$same(true,$ok,'not-connected worker completes after ACK');
$same(1,$driver->finished,'not-connected response is formally finished once');
$pdo=$absent['context']->db->pdo();
$q=$pdo->prepare('SELECT action,target,state,result_code FROM interaction_jobs WHERE interaction_id=?');
$q->execute([$absentId]);$job=$q->fetch(PDO::FETCH_ASSOC);
$same(['action'=>'receipt','target'=>'current','state'=>'done','result_code'=>'not_in_voice'],$job,'not-connected caller reaches the receipt worker and stops at voice presence');
$same(1,(int)$pdo->query('SELECT COUNT(*) FROM operations_ack_receipts')->fetchColumn(),'not-connected job has one durable ACK proof');
$same(0,(int)$pdo->query('SELECT COUNT(*) FROM sessions')->fetchColumn(),'not-connected worker creates no session');
$same(0,(int)$pdo->query('SELECT COUNT(*) FROM attendance')->fetchColumn(),'not-connected worker creates no attendance');
$same(0,count($absent['http']->messages),'not-connected worker creates no shared channel post');
$same(1,count($absent['http']->callbacks),'not-connected worker edits only the original ephemeral response');
$absentCallback=$absent['http']->callbacks[0]['json']??null;
$same('対象の体操VCへの在室を確認できませんでした。',$absentCallback['content']??null,'not-connected follow-up is the fixed presence error');
$absentStages=array_column($events($absent),'stage');
foreach(['http_queue','ack_finish','worker_claim','worker_result','followup','after_response']as$stage){
    $yes(in_array($stage,$absentStages,true),'not-connected diagnostics include '.$stage);
}
$absentFinish=array_search('ack_finish',$absentStages,true);$absentClaim=array_search('worker_claim',$absentStages,true);
$yes(is_int($absentFinish)&&is_int($absentClaim)&&$absentFinish<$absentClaim,'not-connected worker starts only after response finish');
$absent['context']->roster->close();

// 3. While actually connected to the voice channel, the slash command must
//    enqueue a receipt job, ACK first, then let the worker post exactly one
//    fictional shared receipt. The slash command itself must never count attendance.
$inside=$fixture('inside-vc',true);
$user='800000000000001021';
$inside['http']->voices=[$user=>DiscordRestClient::VOICE_CHANNEL_ID];
[$response,$emitted,$ok,$driver,$receiptId]=$deliver($inside,DiscordRestClient::VOICE_CHANNEL_ID);
$same(200,$response->status,'inside-VC command returns Discord success');
$same(['type'=>5,'data'=>['flags'=>64]],$response->body,'inside-VC command defers ephemerally');
$same($response->body,$emitted,'inside-VC defer is emitted');
$same(true,$ok,'inside-VC receipt worker completes after ACK');
$same(1,$driver->finished,'inside-VC response is formally finished once');
$pdo=$inside['context']->db->pdo();
$job=$pdo->query("SELECT interaction_id,action,target,state,result_code FROM interaction_jobs ORDER BY created_at,interaction_id LIMIT 1")->fetch(PDO::FETCH_ASSOC);
$same($receiptId,$job['interaction_id']??null,'receipt job retains the interaction ID');
$same('receipt',$job['action']??null,'/radio-attend dispatches only the receipt action');
$same('current',$job['target']??null,'/radio-attend targets the current scheduled receipt');
$same('done',$job['state']??null,'receipt job reaches done');
$same('receipt_opened',$job['result_code']??null,'first receipt worker opens the clean fixture session');
$same(1,(int)$pdo->query('SELECT COUNT(*) FROM operations_ack_receipts')->fetchColumn(),'receipt has one durable ACK proof');
$same(0,(int)$pdo->query('SELECT COUNT(*) FROM attendance')->fetchColumn(),'/radio-attend slash command never records attendance');
$same(1,count($inside['http']->messages),'receipt worker creates one fictional shared receipt');
$same(1,count($inside['http']->callbacks),'receipt worker sends one fictional private follow-up');
$receiptMessage=$inside['http']->messages[0];
$same(Gateway::ALLOWED_MENTIONS,$receiptMessage['allowed_mentions']??null,'shared receipt suppresses ordinary mentions');
$labels=array_column($receiptMessage['components'][0]['components']??[],'label');
$same(['参加','カード表示'],$labels,'shared receipt exposes the expected buttons');
$insideStages=array_column($events($inside),'stage');
$insideFinish=array_search('ack_finish',$insideStages,true);$insideClaim=array_search('worker_claim',$insideStages,true);
$insideResult=array_search('worker_result',$insideStages,true);$insideFollowup=array_search('followup',$insideStages,true);
$yes(is_int($insideFinish)&&is_int($insideClaim)&&$insideFinish<$insideClaim,'receipt worker starts only after response finish');
$yes(is_int($insideResult)&&is_int($insideFollowup)&&$insideResult<$insideFollowup,'receipt result precedes follow-up');

// 4. The session-specific button is the only path that writes attendance.
//    A second distinct interaction must be idempotent.
[$response,$emitted,$ok,$driver,$attendId]=$deliver($inside,DiscordRestClient::VOICE_CHANNEL_ID,'button');
$same(['type'=>5,'data'=>['flags'=>64]],$response->body,'attendance button defers ephemerally');
$same(true,$ok,'attendance button worker completes after ACK');
$same(1,$driver->finished,'attendance response is formally finished once');
$same(1,(int)$pdo->query('SELECT COUNT(*) FROM attendance')->fetchColumn(),'attendance button records exactly one attendance');
$q=$pdo->prepare('SELECT action,target,state,result_code FROM interaction_jobs WHERE interaction_id=?');
$q->execute([$attendId]);$job=$q->fetch(PDO::FETCH_ASSOC);
$same(['action'=>'attend','target'=>'2026-10-19_0700','state'=>'done','result_code'=>'attended'],$job,'button reaches the attendance worker action');

[$response,$emitted,$ok,$driver,$repeatId]=$deliver($inside,DiscordRestClient::VOICE_CHANNEL_ID,'button');
$same(true,$ok,'repeat attendance button still receives a completed response');
$same(1,(int)$pdo->query('SELECT COUNT(*) FROM attendance')->fetchColumn(),'repeat attendance cannot increment the count');
$q->execute([$repeatId]);$repeat=$q->fetch(PDO::FETCH_ASSOC);
$same('already_attended',$repeat['result_code']??null,'repeat interaction is explicitly idempotent');
$same(3,(int)$pdo->query('SELECT COUNT(*) FROM operations_ack_receipts')->fetchColumn(),'receipt and both buttons each have one ACK proof');

$inside['context']->roster->close();
echo "Attend boundary: $checks assertions passed (signed ingress -> immediate no-write dispatch; ACK -> receipt worker; button -> idempotent attendance; fictional transport and temporary DB only).\n";

