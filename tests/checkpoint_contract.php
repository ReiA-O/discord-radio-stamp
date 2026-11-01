<?php
declare(strict_types=1);
require_once __DIR__.'/artifact_directory.php';

/*
 * Standalone OFFLINE contract test. Every config/key/token is fictional,
 * every database is a newly created local temporary fixture, and all HTTP
 * and Gateway operations use the existing in-process fixture transports.
 * This file never reads a production configuration or database.
 */
require dirname(__DIR__).'/bootstrap.php';
require __DIR__.'/fixtures/OperationsRuntimeFixture.php';

use RadioStamp\Core\FrozenClock;
use RadioStamp\Discord\{DiscordRestClient,HttpAdapter,HttpRequest,HttpResponse};
use RadioStamp\Http\{EndpointDelivery,OperationsEndpoint,PostResponseDriver,Response};
use RadioStamp\Operations\{OperationsRuntime,SafeDiagnostics};

$checks=0;
$same=static function(mixed $expected,mixed $actual,string $why)use(&$checks):void{
    ++$checks;
    if($expected!==$actual){throw new RuntimeException('Checkpoint contract: '.$why);}
};
$yes=static function(bool $actual,string $why)use($same):void{$same(true,$actual,$why);};
$decode=static function(string $payload):array{
    return array_map(
        static fn(string $line):array=>json_decode($line,true,32,JSON_THROW_ON_ERROR),
        array_values(array_filter(explode("\n",$payload)))
    );
};
$read=static function(string $path):string{
    if(!is_file($path)){return '';}
    $text=file_get_contents($path);
    if(!is_string($text)){throw new RuntimeException('Checkpoint contract: fixture log unreadable');}
    return $text;
};
$base=getenv('RADIO_STAMP_TEST_ARTIFACT_DIR')?:sys_get_temp_dir();
$root=rtrim(str_replace('\\','/',$base),'/').'/checkpoint-contract-'.bin2hex(random_bytes(8));
if(!mkdir($root,0700,true)){throw new RuntimeException('Checkpoint contract: fixture root unavailable');}
$privateId='900000000000019001';
$canary='CANARY_CHECKPOINT_CALLBACK_TOKEN_NAME_URL_SIGNATURE_EXCEPTION';

final class CheckpointContractDriver implements PostResponseDriver
{
    public int $finished=0;
    public function available():bool{return true;}
    public function finish():bool{++$this->finished;return true;}
}

final class CheckpointContractHttp implements HttpAdapter
{
    public ?Closure $beforeFirstExchange=null;
    public int $exchanges=0;
    public function __construct(public readonly RuntimeFixtureHttp $inner){}
    public function exchange(HttpRequest $request):HttpResponse
    {
        ++$this->exchanges;
        if($this->exchanges===1&&$this->beforeFirstExchange!==null){($this->beforeFirstExchange)();}
        return $this->inner->exchange($request);
    }
}

// A. Real local append writer: checkpoint persists a prefix, remains buffered,
// and never duplicates a successfully drained prefix on a later checkpoint.
$fileRoot=$root.'/file-writer';
if(!mkdir($fileRoot,0700)){throw new RuntimeException('Checkpoint contract: writer fixture unavailable');}
$file=$fileRoot.'/operations-diagnostic.safe.jsonl';
$diag=SafeDiagnostics::fromConfig([
    'diagnostics_enabled'=>true,'private_root'=>$fileRoot,'participant_user_ids'=>[$privateId],
],null,null,true);
$diag->context($privateId,$privateId,'receipt');
$diag->record('http_prepare',['ok'=>true,'code'=>'config_ready','token'=>$canary]);
$diag->record('ack_finish',['ok'=>true,'finished'=>true,'code'=>'finished']);
$diag->record('ack_handoff',['ok'=>true,'code'=>'ack_handoff_saved']);
$same(false,is_file($file),'buffered ingress performs no early file write');
$same(true,$diag->buffered(),'ingress begins in buffering mode');
$same(true,$diag->checkpoint(),'first checkpoint persists the current batch');
$prefix=$read($file);
$same(['http_prepare','ack_finish','ack_handoff'],array_column($decode($prefix),'stage'),'checkpoint prefix is independently readable before worker work');
$same(true,$diag->buffered(),'checkpoint keeps subsequent work buffered');
$same(true,$diag->checkpoint(),'empty repeated checkpoint succeeds');
$same($prefix,$read($file),'empty repeated checkpoint cannot duplicate prefix');

$diag->record('worker_claim',['ok'=>true,'code'=>'claimed']);
$diag->exception('worker_result',new RuntimeException($canary));
$same($prefix,$read($file),'worker records remain buffered after checkpoint');
$same(true,$diag->checkpoint(),'second populated checkpoint succeeds');
$second=$read($file);
$same(['http_prepare','ack_finish','ack_handoff','worker_claim','worker_result'],array_column($decode($second),'stage'),'second batch appends once in order');
$same(true,$diag->checkpoint(),'second empty checkpoint succeeds');
$same($second,$read($file),'second empty checkpoint cannot duplicate worker batch');
$same(true,$diag->buffered(),'multiple checkpoints preserve buffering');

$diag->record('after_response',['ok'=>true,'code'=>'done','body'=>$canary]);
$same($second,$read($file),'final callback record waits for flush');
$same(true,$diag->flush(),'final flush persists remaining work');
$complete=$read($file);
$same(['http_prepare','ack_finish','ack_handoff','worker_claim','worker_result','after_response'],array_column($decode($complete),'stage'),'final flush preserves complete sequence without duplicates');
$same(false,$diag->buffered(),'final flush resolves buffering');
$same(true,$diag->flush(),'repeated final flush succeeds');
$same(true,$diag->checkpoint(),'checkpoint after final flush succeeds');
$same($complete,$read($file),'resolved flush and checkpoint perform no repeat write');
$yes(!str_contains($complete,$canary)&&!str_contains($complete,$privateId),'checkpoint and flush never serialize token/name/exception or raw identity canaries');
$same('RuntimeException',$decode($complete)[4]['exception']??null,'safe exception category remains available');

// B. A false/throwing writer is best effort. Its drained batch is not retried
// blindly, and either failure must still allow the finished callback to work.
foreach(['false','throw']as$mode){
    $attempts=[];
    $failureDiag=SafeDiagnostics::fromConfig([
        'diagnostics_enabled'=>true,'participant_user_ids'=>[$privateId],
    ],static function(string $batch)use(&$attempts,$mode,$canary):bool{
        $attempts[]=$batch;
        if($mode==='throw'){throw new RuntimeException($canary);}
        return false;
    },null,true);
    $failureDiag->context($privateId,$privateId,'receipt');
    $failureDiag->record('http_prepare',['ok'=>true,'code'=>'config_ready','token'=>$canary]);
    ob_start();
    try{$checkpointOk=$failureDiag->checkpoint();$callerContinued=true;}
    finally{$captured=ob_get_clean();}
    $same(false,$checkpointOk,$mode.' writer failure is observable as false');
    $same(true,$callerContinued,$mode.' writer does not throw through caller');
    $same('',$captured,$mode.' writer exposes no exception text');
    $same(true,$failureDiag->buffered(),$mode.' failed checkpoint keeps buffering enabled');
    $same(1,count($attempts),$mode.' failed batch is attempted once');
    $same(true,$failureDiag->checkpoint(),$mode.' empty retry is a safe no-op');
    $same(1,count($attempts),$mode.' empty retry never duplicates a possibly partial write');

    $failureDiag->record('worker_claim',['ok'=>true,'code'=>'claimed','token'=>$canary]);
    $same(1,count($attempts),$mode.' subsequent records remain buffered');
    $same(false,$failureDiag->flush(),$mode.' final failed writer result is observable');
    $same(2,count($attempts),$mode.' final flush attempts only the new batch');
    $same(['worker_claim'],array_column($decode($attempts[1]),'stage'),$mode.' final attempt cannot replay the failed ingress batch');
    $same(true,$failureDiag->flush(),$mode.' resolved flush is idempotent');
    $same(2,count($attempts),$mode.' resolved flush never retries an uncertain write');
    $yes(!str_contains(implode('',$attempts),$canary)&&!str_contains(implode('',$attempts),$privateId),$mode.' attempted batches contain no secret or identity canary');

    $order=[];$writes=0;$businessRuns=0;$emits=0;
    $callbackDiag=SafeDiagnostics::fromConfig(['diagnostics_enabled'=>true],static function(string $batch)use(&$order,&$writes,$mode,$canary):bool{
        ++$writes;$order[]='writer';
        if($mode==='throw'){throw new RuntimeException($canary);}
        return false;
    },null,true);
    $callbackDriver=new CheckpointContractDriver();
    $delivery=new EndpointDelivery(new Response(200,['type'=>5],'900000000000019002'),static function()use(&$order,&$businessRuns,$callbackDiag):void{
        $order[]='callback';
        $callbackDiag->record('ack_handoff',['ok'=>true,'code'=>'ack_handoff_saved']);
        $callbackDiag->checkpoint();
        ++$businessRuns;$order[]='business';
        $callbackDiag->record('worker_result',['ok'=>true,'code'=>'done']);
    },$callbackDiag);
    ob_start();
    try{$delivered=$delivery->deliver($callbackDriver,static function(Response $response)use(&$emits):void{++$emits;});}
    finally{$captured=ob_get_clean();}
    $same(true,$delivered,$mode.' failed checkpoint cannot fail a finished business delivery');
    $same(1,$callbackDriver->finished,$mode.' response finishes exactly once');
    $same(1,$emits,$mode.' prepared response is emitted exactly once');
    $same(1,$businessRuns,$mode.' business callback proceeds after failed checkpoint');
    $same(['callback','writer','business','writer'],$order,$mode.' callback checkpoint precedes business and final writer follows business');
    $same(2,$writes,$mode.' callback performs a checkpoint and a distinct final flush');
    $same('',$captured,$mode.' failed delivery writers leak no exception message');
}

// C. Actual OperationsEndpoint path, signed fictional request and temporary DB.
// Observe the diagnostic file at the first injected HTTP exchange rather than
// checking only the final log: this proves persistence happened before worker
// I/O. EndpointDelivery must already have finished and queue recovery must have
// imported the durable handoff proof before this observer can run.
$endpointRoot=$root.'/endpoint';
foreach([$endpointRoot,$endpointRoot.'/private',$endpointRoot.'/public']as$directory){
    if(!mkdir($directory,0700)){throw new RuntimeException('Checkpoint contract: endpoint fixture unavailable');}
}
$config=runtimeFixtureConfig($endpointRoot,'manual',false);
$config['diagnostics_enabled']=true;
$configPath=$endpointRoot.'/private/config.php';
if(file_put_contents($configPath,"<?php\nreturn ".var_export($config,true).";\n")===false){throw new RuntimeException('Checkpoint contract: fictional config write failed');}
$clock=new FrozenClock('2026-10-19T07:05:00+09:00');
$inner=new RuntimeFixtureHttp();
$http=new CheckpointContractHttp($inner);
$runtime=new OperationsRuntime($http,fn()=>$inner->transport(),$clock);
$context=$runtime->build($config,static fn():bool=>true);
$id='900000000000019003';
$actor=$config['participant_user_ids'][0];
$callbackToken='fictional_checkpoint_callback_token_000000';
$body=json_encode([
    'id'=>$id,'application_id'=>DiscordRestClient::APPLICATION_ID,'guild_id'=>DiscordRestClient::GUILD_ID,
    'channel_id'=>DiscordRestClient::VOICE_CHANNEL_ID,'type'=>2,'token'=>$callbackToken,
    'member'=>['user'=>['id'=>$actor,'username'=>$canary]],
    'data'=>['type'=>1,'name'=>'radio-attend'],
],JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);
$timestamp=(string)$clock->now()->getTimestamp();
$keys=sodium_crypto_sign_seed_keypair(str_repeat("\x17",32));
$server=[
    'REQUEST_METHOD'=>'POST','HTTP_X_SIGNATURE_TIMESTAMP'=>$timestamp,
    'HTTP_X_SIGNATURE_ED25519'=>bin2hex(sodium_crypto_sign_detached($timestamp.$body,sodium_crypto_sign_secretkey($keys))),
];
$endpointDriver=new CheckpointContractDriver();
$logPath=$endpointRoot.'/private/operations-diagnostic.safe.jsonl';
$delivery=(new OperationsEndpoint($endpointRoot.'/public',$endpointDriver,$runtime))->prepare($server,$body,$configPath);
$same(200,$delivery->response->status,'real endpoint accepts the signed fictional attendance request');
$same(['type'=>5,'data'=>['flags'=>64]],$delivery->response->body,'real endpoint prepares an ephemeral defer');
$same(0,$endpointDriver->finished,'prepare does not finish or run the callback');
$same(0,$http->exchanges,'prepare performs no injected HTTP exchange');
$same(false,is_file($logPath),'prepare performs no diagnostic writer I/O');
$same(0,(int)$context->db->pdo()->query('SELECT COUNT(*) FROM operations_ack_receipts')->fetchColumn(),'prepare has no premature durable ACK receipt');
$same([],glob($endpointRoot.'/private/ack-handoff/*.ack.json')?:[],'prepare creates no premature handoff proof file');

$observed=null;
$http->beforeFirstExchange=static function()use(&$observed,$endpointDriver,$read,$decode,$logPath,$context,$id):void{
    $payload=$read($logPath);$rows=$decode($payload);$stages=array_column($rows,'stage');
    $ackSaved=array_values(array_filter($rows,static fn(array $row):bool=>$row['stage']==='ack_handoff'&&($row['code']??null)==='ack_handoff_saved'));
    $statement=$context->db->pdo()->prepare('SELECT COUNT(*) FROM operations_ack_receipts WHERE interaction_id=?');
    $statement->execute([$id]);
    $observed=[
        'finished'=>$endpointDriver->finished,'payload'=>$payload,'stages'=>$stages,
        'ackSaved'=>count($ackSaved),'ackReceipts'=>(int)$statement->fetchColumn(),
    ];
};
$emitted=null;
$same(true,$delivery->deliver($endpointDriver,static function(Response $response)use(&$emitted):void{$emitted=$response->body;}),'actual endpoint callback completes using fictional transports');
$same($delivery->response->body,$emitted,'actual endpoint emits its prepared defer exactly');
$yes(is_array($observed),'worker reaches the injected HTTP observer');
$same(1,$observed['finished']??null,'response finish precedes first worker HTTP exchange');
$same(1,$observed['ackSaved']??null,'ACK-saved diagnostic is already persisted at first worker HTTP exchange');
$same(1,$observed['ackReceipts']??null,'durable ACK proof has been recovered before first worker HTTP exchange');
$yes(in_array('ack_finish',$observed['stages']??[],true),'finished diagnostic is independently readable before worker I/O');
$same(false,in_array('worker_claim',$observed['stages']??[],true),'worker diagnostics are still buffered when first HTTP exchange begins');
$same(false,in_array('worker_result',$observed['stages']??[],true),'final worker result cannot appear in the checkpoint prefix');
$same(false,in_array('after_response',$observed['stages']??[],true),'callback completion cannot appear in the checkpoint prefix');
$finalLog=$read($logPath);$finalRows=$decode($finalLog);$finalStages=array_column($finalRows,'stage');
$same(1,count(array_filter($finalRows,static fn(array $row):bool=>$row['stage']==='ack_handoff'&&($row['code']??null)==='ack_handoff_saved')),'final flush never duplicates the checkpoint ACK-saved diagnostic');
$yes(in_array('worker_claim',$finalStages,true)&&in_array('worker_result',$finalStages,true)&&in_array('after_response',$finalStages,true),'final flush appends worker and callback completion diagnostics');
$yes(is_string($observed['payload']??null)&&str_starts_with($finalLog,$observed['payload']),'final diagnostic log preserves the exact pre-worker prefix');
$yes(!str_contains($finalLog,$canary)&&!str_contains($finalLog,$callbackToken)&&!str_contains($finalLog,$config['bot_token'])&&!str_contains($finalLog,$config['token_encryption_key_hex'])&&!str_contains($finalLog,$actor),'actual endpoint diagnostics contain no fictional token/key/name/raw-user canary');
$same(0,(int)$context->db->pdo()->query('SELECT COUNT(*) FROM sessions')->fetchColumn(),'absent-voice fixture creates no session');
$same(0,(int)$context->db->pdo()->query('SELECT COUNT(*) FROM attendance')->fetchColumn(),'absent-voice fixture records no attendance');
$same(0,count($inner->messages),'absent-voice fixture creates no shared message');
$same(1,count($inner->callbacks),'fixture returns only the expected private follow-up');
$same('not_in_voice',$context->db->pdo()->query('SELECT result_code FROM interaction_jobs')->fetchColumn(),'real worker safely stops at absent voice');
$context->roster->close();

echo "Checkpoint contract: $checks assertions passed (offline fictional transports, local temporary fixtures; persistence observed before first worker HTTP exchange).\n";
