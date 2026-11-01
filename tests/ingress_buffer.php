<?php
declare(strict_types=1);
require_once __DIR__.'/artifact_directory.php';
require dirname(__DIR__).'/bootstrap.php';

use RadioStamp\Http\{EndpointDelivery,PostResponseDriver,Response};
use RadioStamp\Operations\SafeDiagnostics;

$checks=0;
$check=static function(bool $ok,string $why)use(&$checks):void{if(!$ok){throw new RuntimeException('Ingress buffer test failed: '.$why);}$checks++;};
$decode=static fn(string $payload):array=>array_map(static fn(string $line):array=>json_decode($line,true,32,JSON_THROW_ON_ERROR),array_values(array_filter(explode("\n",$payload))));

final class IngressBufferDriver implements PostResponseDriver
{
    public int $finishes=0;
    private array $events;
    public function __construct(array &$events,private bool $supported=true,private bool $success=true,private bool $throws=false){$this->events=&$events;}
    public function available():bool{$this->events[]='available';return $this->supported;}
    public function finish():bool{$this->finishes++;$this->events[]='finish';if($this->throws){throw new RuntimeException('CANARY_FINISH_SECRET');}return $this->success;}
}

// Plain delivery needs no finish capability, while each post-response hook still does.
$events=[];$driver=new IngressBufferDriver($events,false);
$plain=new EndpointDelivery(new Response(503,['error'=>'unavailable']));
$check($plain->deliver($driver,static function()use(&$events):void{$events[]='emit';}),'plain response succeeds without finish capability');
$check($events===['emit']&&$driver->finishes===0,'plain response never consults the finish driver');

$events=[];$diagnosticPayload='';
$diagnosticOnly=SafeDiagnostics::fromConfig(['diagnostics_enabled'=>true],static function(string $batch)use(&$events,&$diagnosticPayload):bool{$events[]='writer';$diagnosticPayload=$batch;return true;},null,true);
$driver=new IngressBufferDriver($events);
$delivery=new EndpointDelivery(new Response(200,['type'=>1]),null,$diagnosticOnly);
$check($delivery->deliver($driver,static function()use(&$events):void{$events[]='emit';}),'diagnostic-only response finishes successfully');
$check($events===['emit','available','finish','writer']&&array_column($decode($diagnosticPayload),'stage')===['ack_emit','ack_finish'],'diagnostic-only writer follows confirmed finish');

$events=[];$driver=new IngressBufferDriver($events);
$markerOnly=static function(string $signal)use(&$events):void{$events[]='marker_'.$signal;};
$delivery=new EndpointDelivery(new Response(200,['type'=>1]),null,null,$markerOnly);
$check($delivery->deliver($driver,static function()use(&$events):void{$events[]='emit';}),'marker-only response finishes successfully');
$check($events===['emit','marker_finish_attempted','available','finish','marker_response_finished','marker_finished'],'marker-only response retains finish and marker order');

// Buffered diagnostics are bounded, private-schema-only, and flushed once.
$calls=0;$payload='';
$diag=SafeDiagnostics::fromConfig(['diagnostics_enabled'=>true],static function(string $batch)use(&$calls,&$payload):bool{$calls++;$payload.=$batch;return true;},null,true);
foreach(['http_prepare','http_action','http_queue','http_controller','ack_emit','ack_finish']as$stage){$diag->record($stage,['ok'=>true,'code'=>$stage==='ack_emit'?'emitted':'ok']);}
$check($calls===0&&$diag->buffered(),'buffering performs no writer call');
$check($diag->flush()&&$calls===1&&!$diag->buffered(),'first flush writes one batch');
$check(array_column($decode($payload),'stage')===['http_prepare','http_action','http_queue','http_controller','ack_emit','ack_finish'],'buffer preserves stage order');
$check($diag->flush()&&$calls===1,'flush is idempotent');

$overflowCalls=0;$overflowPayload='';
$overflow=SafeDiagnostics::fromConfig(['diagnostics_enabled'=>true],static function(string $batch)use(&$overflowCalls,&$overflowPayload):bool{$overflowCalls++;$overflowPayload=$batch;return true;},null,true);
for($i=0;$i<40;$i++){$overflow->record('cycle',['ok'=>true,'code'=>'ok','count'=>$i]);}
$check($overflow->flush()&&$overflowCalls===1,'overflow flush remains one batch');
$overflowRows=$decode($overflowPayload);
$check(count($overflowRows)===32&&($overflowRows[array_key_last($overflowRows)]['dropped_events']??null)===8,'32-event cap reports fixed drop count');
$check(strlen($overflowPayload)<=32768,'buffer stays within 32 KiB');

$discardCalls=0;$discard=SafeDiagnostics::fromConfig(['diagnostics_enabled'=>true],static function()use(&$discardCalls):bool{$discardCalls++;return true;},null,true);
$discard->record('http_prepare',['code'=>'ok']);$discard->discard();$discard->record('http_prepare',['code'=>'ok']);
$check($discard->flush()&&$discardCalls===0,'discard is terminal and performs no later or fallback write');

$canary='CANARY_IP_UA_URI_QUERY_BODY_LENGTH_SIGNATURE_INTERACTION_TOKEN_PATH_EXCEPTION';$privateId='900000000000000123';$safePayload='';
$safe=SafeDiagnostics::fromConfig(['diagnostics_enabled'=>true,'participant_user_ids'=>[$privateId]],static function(string $batch)use(&$safePayload):bool{$safePayload=$batch;return true;},null,true);
$safe->context($privateId,$privateId,'status');$safe->record('http_prepare',['code'=>$canary,'ip'=>$canary,'ua'=>$canary,'uri'=>$canary,'body'=>$canary,'token'=>$canary,'exception'=>$canary]);$safe->flush();
$check(!str_contains($safePayload,$canary)&&!str_contains($safePayload,$privateId),'buffer keeps strict privacy allowlist');

// Successful delivery closes the response and completes durable callback work before either writer starts.
$events=[];$slowPayload='';
$slow=SafeDiagnostics::fromConfig(['diagnostics_enabled'=>true],static function(string $batch)use(&$events,&$slowPayload):bool{$events[]='writer_start';usleep(4000000);$slowPayload=$batch;$events[]='writer_end';return true;},null,true);
$marker=static function(string $signal)use(&$events):void{$events[]='marker_'.$signal;};
$driver=new IngressBufferDriver($events);
$delivery=new EndpointDelivery(new Response(200,['type'=>5],'900000000000000124'),static function()use(&$events):void{$events[]='callback';},$slow,$marker);
$check($delivery->deliver($driver,static function()use(&$events):void{$events[]='emit';}),'successful delivery returns true');
$check($events===['emit','marker_finish_attempted','available','finish','marker_response_finished','callback','marker_finished','writer_start','writer_end'],'strict emit/finish/callback/writer order');
$check(array_column($decode($slowPayload),'stage')===['ack_emit','ack_finish','after_response'],'delivery batch stage order');
$check(!$delivery->deliver($driver,static function():void{}),'second deliver has no side effects');

// No confirmed finish means no callback and no diagnostic writer fallback.
foreach([
    'unavailable'=>[false,true,false,true],
    'finish_false'=>[true,false,false,false],
    'finish_throw'=>[true,true,true,false],
]as$name=>[$supported,$success,$throws,$expectedNoCallbackResult]){
    $events=[];$writes=0;$callbacks=0;
    $diag=SafeDiagnostics::fromConfig(['diagnostics_enabled'=>true],static function()use(&$writes):bool{$writes++;return true;},null,true);
    $driver=new IngressBufferDriver($events,$supported,$success,$throws);
    $delivery=new EndpointDelivery(new Response(200,['type'=>5],'900000000000000125'),static function()use(&$callbacks):void{$callbacks++;},$diag);
    $check(!$delivery->deliver($driver,static function():void{})&&$callbacks===0&&$writes===0,$name.' blocks callback and writer');
    $noCallbackDiag=SafeDiagnostics::fromConfig(['diagnostics_enabled'=>true],static function()use(&$writes):bool{$writes++;return true;},null,true);
    $noCallbackDriver=new IngressBufferDriver($events,$supported,$success,$throws);
    $noCallback=new EndpointDelivery(new Response(200,['type'=>1]),null,$noCallbackDiag);
    $check($noCallback->deliver($noCallbackDriver,static function():void{})===$expectedNoCallbackResult&&$writes===0,$name.' callbackless fallback is explicit and write-free');
}

$events=[];$writes=0;$callbacks=0;$diag=SafeDiagnostics::fromConfig(['diagnostics_enabled'=>true],static function()use(&$writes):bool{$writes++;return true;},null,true);
$emitterFailure=new EndpointDelivery(new Response(200,['type'=>5],'900000000000000126'),static function()use(&$callbacks):void{$callbacks++;},$diag);
$check(!$emitterFailure->deliver(new IngressBufferDriver($events),static function():void{throw new TypeError('CANARY_EMITTER_SECRET');})&&$callbacks===0&&$writes===0,'emitter exception never finishes, calls back, or writes');

$events=[];$throwPayload='';$diag=SafeDiagnostics::fromConfig(['diagnostics_enabled'=>true],static function(string $batch)use(&$throwPayload):bool{$throwPayload=$batch;return true;},null,true);
$callbackFailure=new EndpointDelivery(new Response(200,['type'=>5],'900000000000000127'),static function():void{throw new RuntimeException('CANARY_CALLBACK_SECRET');},$diag);
$check(!$callbackFailure->deliver(new IngressBufferDriver($events),static function():void{}),'callback exception returns false after finish');
$check(str_contains($throwPayload,'"exception":"RuntimeException"')&&!str_contains($throwPayload,'CANARY_CALLBACK_SECRET'),'callback exception is classified without message');

foreach(['false','throw']as$writerMode){
    $events=[];$callbacks=0;
    $diag=SafeDiagnostics::fromConfig(['diagnostics_enabled'=>true],static function()use($writerMode):bool{if($writerMode==='throw'){throw new RuntimeException('CANARY_WRITER_SECRET');}return false;},null,true);
    $delivery=new EndpointDelivery(new Response(200,['type'=>5],'900000000000000128'),static function()use(&$callbacks):void{$callbacks++;},$diag);
    ob_start();$ok=$delivery->deliver(new IngressBufferDriver($events),static function():void{});$output=ob_get_clean();
    $check($ok&&$callbacks===1&&$output==='','writer '.$writerMode.' cannot change finished response or callback');
}

echo "Ingress buffer: {$checks} assertions passed (offline only).\n";
