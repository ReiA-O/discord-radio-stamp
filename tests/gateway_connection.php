<?php
declare(strict_types=1);
require_once __DIR__.'/artifact_directory.php';
require dirname(__DIR__).'/bootstrap.php';require __DIR__.'/fixtures/OperationsRuntimeFixture.php';
use RadioStamp\Core\{Database,FrozenClock};
use RadioStamp\Discord\{WebSocketFrameCodec,GatewayRosterSession,ServerOperationsClient,DiscordRestClient,MemberActionFence};
$checks=0;$same=static function(mixed $a,mixed $b,string $why)use(&$checks):void{++$checks;if($a!==$b){throw new RuntimeException('Gateway connection: '.$why);}};
$reject=static function(callable $call):bool{try{$call();return false;}catch(Throwable){return true;}};
$server=static function(string $text,int $opcode=1,bool $fin=true):string{$n=strlen($text);return chr(($fin?128:0)|$opcode).($n<126?chr($n):($n<=65535?chr(126).pack('n',$n):chr(127).pack('NN',0,$n))).$text;};
$codec=new WebSocketFrameCodec();$frame=$server('{"op":11}');
$same([],$codec->push(substr($frame,0,1)),'partial header buffered');$same([['opcode'=>1,'payload'=>'{"op":11}']],$codec->push(substr($frame,1)),'partial frame completed');
$codec=new WebSocketFrameCodec();$same([],$codec->push($server('{"op":',1,false)),'fragment waits');
$events=$codec->push($server('ping',9).$server('11}',0));$same(9,$events[0]['opcode'],'ping allowed during fragment');$same('{"op":11}',$events[1]['payload'],'fragmented JSON assembled');
foreach([125,126,65535,65536]as$n){$text=str_repeat('a',$n);$events=(new WebSocketFrameCodec())->push($server($text));$same($text,$events[0]['payload'],'extended length '.$n);}
$client=WebSocketFrameCodec::client('hello');$same(true,(ord($client[1])&128)!==0,'client frame masked');
$mask=substr($client,2,4);$text='';for($i=0;$i<5;$i++){$text.=$client[6+$i]^$mask[$i%4];}$same('hello',$text,'mask round trip');
foreach([chr(0xC1).chr(0),chr(0x81).chr(0x80).'1234', $server('x',0),$server('x',2),$server(str_repeat('x',126),9),$server("\xff")]as$bad){$same(true,$reject(fn()=>(new WebSocketFrameCodec())->push($bad)),'invalid frame refused');}
$same(true,$reject(fn()=>WebSocketFrameCodec::client(str_repeat('x',65537))),'outgoing packet bounded');

$db=new Database(':memory:');$clock=new FrozenClock('2026-10-19T07:18:00+09:00');$http=new RuntimeFixtureHttp();$owner='800000000000001021';$other='900000000000000102';
$http->voices=[$owner=>DiscordRestClient::VOICE_CHANNEL_ID,$other=>DiscordRestClient::VOICE_CHANNEL_ID];
$client=new ServerOperationsClient($db,$http,'fictional_gateway_token_000000',['enabled'=>true]);
$session=new GatewayRosterSession($db,$client,$clock,'fictional_gateway_token_000000',fn()=>$http->transport(),static fn()=>true);
$same(true,$session->start(),'complete baseline plus ACK accepted');$same(true,$session->roster()->known,'roster known');$same([$owner,$other],$session->roster()->memberIds,'all current voice users included');
$ops=array_column($http->gateway->sent,'op');$same(false,in_array(4,$ops,true),'never sends voice connection opcode');
$identify=array_values(array_filter($http->gateway->sent,static fn(array $p):bool=>$p['op']===2))[0];$same(129,$identify['d']['intents'],'only guild and voice-state data intents');
$state=$session->voiceState($owner);$fence=new MemberActionFence(DiscordRestClient::GUILD_ID,$owner,DiscordRestClient::VOICE_CHANNEL_ID,$state->generation,$state->observedAt);
$http->gateway->change($other,null);$session->pump();$same(true,$session->matches($fence),'unrelated person change does not invalidate this person');
$http->gateway->change($owner,'900000000000000199');$same(false,$session->matches($fence),'observable move invalidates fence');
$same(false,str_contains(print_r($session,true),'fictional_gateway_token'),'session debug redacts token');
$same(false,str_contains(print_r($session,true),'do-not-retain'),'session debug contains no resume/session values');
$session->close();$same(false,$session->roster()->known,'disconnect invalidates roster');
$same(1,$http->connections,'one bounded connection');$same(false,$session->start(),'no reconnect loop in same worker');

$db=new Database(':memory:');$http=new RuntimeFixtureHttp();$http->sequenceGap=true;
$client=new ServerOperationsClient($db,$http,'fictional_gateway_token_000000',['enabled'=>true]);
$bad=new GatewayRosterSession($db,$client,$clock,'fictional_gateway_token_000000',fn()=>$http->transport(),static fn()=>true);
$same(false,$bad->start(),'sequence gap rejects baseline');$same(false,$bad->roster()->known,'gap cannot be treated as empty room');
$db=new Database(':memory:');$mono=0.0;$http=new RuntimeFixtureHttp();$transport=$http->transport();$transport->autoAck=false;
$transport->onWait=static function(float $wait)use(&$mono):void{$mono+=max(0.1,$wait);};
$client=new ServerOperationsClient($db,$http,'fictional_gateway_token_000000',['enabled'=>true]);
$lost=new GatewayRosterSession($db,$client,$clock,'fictional_gateway_token_000000',fn()=>$transport,static fn()=>true,static function()use(&$mono):float{return $mono;});
$same(false,$lost->start(),'missing heartbeat ACK fails closed');$same(true,$mono<=8.5,'startup bounded in simulated time');
$db=new Database(':memory:');$http=new RuntimeFixtureHttp();$client=new ServerOperationsClient($db,$http,'fictional_gateway_token_000000',['enabled'=>true]);
$day=$clock->now()->format('Y-m-d');$db->pdo()->exec('CREATE TABLE gateway_closure_attempts(session_id TEXT PRIMARY KEY,attempts INTEGER NOT NULL)');$db->pdo()->prepare('INSERT INTO gateway_closure_attempts VALUES(?,3)')->execute(['probe-'.gmdate('Y-m-d',$clock->now()->getTimestamp())]);
$limited=new GatewayRosterSession($db,$client,$clock,'fictional_gateway_token_000000',fn()=>$http->transport(),static fn()=>true);
$same(false,$limited->start(),'local identify retry cap');$same(0,$http->connections,'cap prevents socket opening');
echo "Gateway connection: {$checks} assertions passed (framing and fake event transport only; no audio/UDP or real connection).\n";
