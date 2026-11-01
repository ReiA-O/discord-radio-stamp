<?php
declare(strict_types=1);
require_once __DIR__.'/artifact_directory.php';

/* Offline only: generated config, temporary SQLite, in-process fictional transports. */
require dirname(__DIR__).'/bootstrap.php';

use RadioStamp\Core\{FrozenClock,RadioService};
use RadioStamp\Discord\{DiscordRestClient,GatewayRosterSession,RestStateStore,ServerOperationsClient};
use RadioStamp\Http\{InteractionQueue,OperationsEndpoint,PostResponseDriver};
use RadioStamp\Operations\{AckHandoff,OperationsRuntime};

// Fresh child process proves lightweight ingress does not even load the classes
// needed to construct the old HTTP client/Gateway/service/worker object graph.
if(($argv[1]??null)==='--cold-ingress'){
    $path=$argv[2]??'';
    $config=require $path;
    $ingress=(new OperationsRuntime())->prepareIngress($config);
    $unexpected=[];
    foreach([
        'RadioStamp\\Discord\\CurlHttpAdapter',
        'RadioStamp\\Discord\\GatewayVoiceTracker',
        'RadioStamp\\Discord\\ServerOperationsGateway',
        'RadioStamp\\Http\\InteractionWorker',
        'RadioStamp\\Operations\\OperationsContext',
    ]as$class){if(class_exists($class,false)){$unexpected[]=$class;}}
    echo json_encode([
        'queue'=>$ingress['queue'] instanceof InteractionQueue,
        'handoff'=>$ingress['handoff'] instanceof AckHandoff,
        'unexpected_runtime_classes'=>$unexpected,
    ],JSON_THROW_ON_ERROR)."\n";
    exit(0);
}

require __DIR__.'/fixtures/OperationsRuntimeFixture.php';
$checks=0;
$same=static function(mixed $expected,mixed $actual,string $why)use(&$checks):void{
    ++$checks;
    if($expected!==$actual){throw new RuntimeException('Ingress preflight contract: '.$why);}
};
$base=getenv('RADIO_STAMP_TEST_ARTIFACT_DIR')?:sys_get_temp_dir();
$root=rtrim(str_replace('\\','/',$base),'/').'/ingress-preflight-'.bin2hex(random_bytes(8));
foreach([$root,$root.'/private',$root.'/public']as$directory){
    if(!mkdir($directory,0700,true)){throw new RuntimeException('Ingress preflight contract: fixture directory unavailable');}
}
$config=runtimeFixtureConfig($root,'manual',false);
$clock=new FrozenClock('2026-10-19T07:05:00+09:00');
$http=new RuntimeFixtureHttp();
$runtime=new OperationsRuntime($http,fn()=>$http->transport(),$clock);
$context=$runtime->build($config,static fn():bool=>true);
$pdo=$context->db->pdo();
$schema=static fn():array=>$pdo->query('SELECT type,name,tbl_name,sql FROM sqlite_master ORDER BY type,name')->fetchAll(PDO::FETCH_ASSOC);
$beforeSchema=$schema();
$beforeChanges=(int)$pdo->query('SELECT total_changes()')->fetchColumn();

// query_only makes any accidental CREATE/ALTER/INSERT/UPDATE in the shared
// validators fail, even when invoked on otherwise writable provisioned storage.
$pdo->exec('PRAGMA query_only=ON');
RestStateStore::requireExistingSchema($pdo);
ServerOperationsClient::requireExistingSchema($pdo);
GatewayRosterSession::requireExistingSchema($pdo);
$same(1,(int)$pdo->query('PRAGMA query_only')->fetchColumn(),'shared validators run on a query-only connection');
$same($beforeSchema,$schema(),'shared validators do not change the schema');
$same($beforeChanges,(int)$pdo->query('SELECT total_changes()')->fetchColumn(),'shared validators perform no data writes');
$pdo->exec('PRAGMA query_only=OFF');

$configPath=$root.'/private/config.php';
$writeConfig=static function(array $value)use($configPath):void{
    if(file_put_contents($configPath,"<?php\nreturn ".var_export($value,true).";\n")===false){throw new RuntimeException('Ingress preflight contract: fictional config write failed');}
};
$driver=new class implements PostResponseDriver{
    public int $finished=0;
    public function available():bool{return true;}
    public function finish():bool{++$this->finished;return true;}
};
$sequence=0;
$signed=static function(int $type=2)use(&$sequence,$clock):array{
    $body=json_encode([
        'id'=>(string)(900000000000029000+ ++$sequence),
        'application_id'=>DiscordRestClient::APPLICATION_ID,'guild_id'=>DiscordRestClient::GUILD_ID,
        'channel_id'=>DiscordRestClient::VOICE_CHANNEL_ID,'type'=>$type,
        'token'=>'fictional_ingress_preflight_callback_000000',
        'member'=>['user'=>['id'=>'800000000000001021','username'=>'fictional']],
        'data'=>['type'=>1,'name'=>'radio-status'],
    ],JSON_THROW_ON_ERROR);
    $timestamp=(string)$clock->now()->getTimestamp();
    $keys=sodium_crypto_sign_seed_keypair(str_repeat("\x17",32));
    return [[
        'REQUEST_METHOD'=>'POST','HTTP_X_SIGNATURE_TIMESTAMP'=>$timestamp,
        'HTTP_X_SIGNATURE_ED25519'=>bin2hex(sodium_crypto_sign_detached($timestamp.$body,sodium_crypto_sign_secretkey($keys))),
    ],$body];
};

// Missing is distinct from explicit null. Endpoint assertions preserve the
// observable old contract: invalid freshness never enqueues or acknowledges.
$cases=[
    ['missing',false,null,true],['zero',true,0,true],['five',true,5,true],
    ['null',true,null,false],['negative',true,-1,false],['six',true,6,false],
    ['string',true,'5',false],['float',true,5.0,false],['bool',true,true,false],
];
foreach($cases as[$name,$present,$value,$valid]){
    $case=$config;
    if($present){$case['max_roster_age_seconds']=$value;}else{unset($case['max_roster_age_seconds']);}
    $accepted=true;
    try{RadioService::requireValidRosterFreshness($case);}catch(InvalidArgumentException){$accepted=false;}
    $same($valid,$accepted,$name.' matches the shared constructor freshness contract');
    $writeConfig($case);
    $jobs=(int)$pdo->query('SELECT COUNT(*) FROM interaction_jobs')->fetchColumn();
    $version=(int)$pdo->query('PRAGMA schema_version')->fetchColumn();
    [$server,$body]=$signed();
    $delivery=(new OperationsEndpoint($root.'/public',$driver,$runtime))->prepare($server,$body,$configPath);
    $same($valid?200:503,$delivery->response->status,$name.' preserves rejection before ACK');
    $same($jobs+($valid?1:0),(int)$pdo->query('SELECT COUNT(*) FROM interaction_jobs')->fetchColumn(),$name.' cannot enqueue an invalid freshness config');
    $same($version,(int)$pdo->query('PRAGMA schema_version')->fetchColumn(),$name.' leaves provisioned schema unchanged');
    $same(0,count($http->calls),$name.' performs no fictional HTTP before ACK');
    $same(0,(int)$pdo->query('SELECT COUNT(*) FROM operations_ack_receipts')->fetchColumn(),$name.' creates no premature ACK receipt');
}
$same(0,$driver->finished,'preparation never runs finish or worker work');

// Freshness is a worker/queued-operation guard. Keep prior protocol behavior:
// signed PING and method rejection do not require worker construction.
$malformed=$config;$malformed['max_roster_age_seconds']=null;$writeConfig($malformed);
$jobs=(int)$pdo->query('SELECT COUNT(*) FROM interaction_jobs')->fetchColumn();
[$server,$body]=$signed(1);
$ping=(new OperationsEndpoint($root.'/public',$driver,$runtime))->prepare($server,$body,$configPath);
$same(200,$ping->response->status,'malformed worker freshness does not change signed PING success');
$same(['type'=>1],$ping->response->body,'PING retains its protocol response');
$get=(new OperationsEndpoint($root.'/public',$driver,$runtime))->prepare(['REQUEST_METHOD'=>'GET'],'',$configPath);
$same(405,$get->response->status,'malformed worker freshness does not change GET method rejection');
$same($jobs,(int)$pdo->query('SELECT COUNT(*) FROM interaction_jobs')->fetchColumn(),'PING and GET never enqueue despite malformed worker freshness');

$writeConfig($config);
$ini=php_ini_loaded_file();
if(!is_string($ini)){throw new RuntimeException('Ingress preflight contract: explicit CLI ini required');}
$process=proc_open([PHP_BINARY,'-c',$ini,__FILE__,'--cold-ingress',$configPath],[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes,dirname(__DIR__));
if(!is_resource($process)){throw new RuntimeException('Ingress preflight contract: cold child unavailable');}
fclose($pipes[0]);$stdout=stream_get_contents($pipes[1]);$stderr=stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);$exit=proc_close($process);
$same(0,$exit,'cold ingress child succeeds with existing storage');
$same('',$stderr,'cold ingress child emits no warning or secret-bearing error');
$cold=json_decode($stdout,true,32,JSON_THROW_ON_ERROR);
$same(true,$cold['queue']??null,'cold ingress returns the durable queue primitive');
$same(true,$cold['handoff']??null,'cold ingress returns the handoff primitive');
$same([],$cold['unexpected_runtime_classes']??null,'cold ingress avoids the old REST/Gateway/worker object graph');
$same($beforeSchema,$schema(),'cold ingress leaves every schema object unchanged');
$same($jobs,(int)$pdo->query('SELECT COUNT(*) FROM interaction_jobs')->fetchColumn(),'cold preflight never enqueues a job itself');
$same(0,(int)$pdo->query('SELECT COUNT(*) FROM operations_ack_receipts')->fetchColumn(),'cold preflight never manufactures an ACK receipt');
$context->roster->close();

echo "Ingress preflight contract: $checks assertions passed (read-only schema validators, exact freshness guards, protocol preservation, cold ingress without runtime object graph; offline only).\n";
