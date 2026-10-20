<?php
declare(strict_types=1);

/* Test-local PHP I/O doubles: the canonical TLS transport never opens a network socket here. */
namespace RadioStamp\Discord {
    final class GatewayGuardLocalIo
    {
        public static ?\Closure $hook=null;
        public static int $connects=0,$writes=0,$headerReads=0,$readWaits=0,$writeWaits=0,$reads=0;
        public static array $headers=[],$incoming=[],$opcodes=[];
        public static function reset():void
        {
            self::$hook=null;self::$connects=self::$writes=self::$headerReads=self::$readWaits=self::$writeWaits=self::$reads=0;
            self::$headers=self::$incoming=self::$opcodes=[];
        }
        public static function phase(string $phase):void{if(self::$hook!==null){(self::$hook)($phase);}}
    }
    function stream_socket_client(string $address,?int &$code=null,?string &$detail=null,?float $timeout=null,int $flags=0,$context=null)
    {
        ++GatewayGuardLocalIo::$connects;GatewayGuardLocalIo::phase('connect');
        return \fopen('php://temp','w+b');
    }
    function stream_set_timeout($stream,int $seconds,?int $microseconds=null):bool{return true;}
    function stream_set_blocking($stream,bool $enable):bool{GatewayGuardLocalIo::phase('handshake_ready');return true;}
    function fwrite($stream,string $bytes,?int $length=null):int|false
    {
        ++GatewayGuardLocalIo::$writes;
        if(str_starts_with($bytes,'GET ')){
            if(preg_match('/Sec-WebSocket-Key: ([^\r\n]+)/',$bytes,$match)!==1){throw new \RuntimeException('test handshake missing key');}
            $accept=base64_encode(sha1($match[1].'258EAFA5-E914-47DA-95CA-C5AB0DC85B11',true));
            GatewayGuardLocalIo::$headers=["HTTP/1.1 101 Switching Protocols\r\n","Upgrade: websocket\r\n","Connection: Upgrade\r\n","Sec-WebSocket-Accept: {$accept}\r\n","\r\n"];
            GatewayGuardLocalIo::phase('handshake_write');
        }else{GatewayGuardLocalIo::$opcodes[]=ord($bytes[0])&15;GatewayGuardLocalIo::phase('frame_write');}
        return strlen($bytes);
    }
    function fgets($stream,?int $length=null):string|false
    {
        ++GatewayGuardLocalIo::$headerReads;$line=array_shift(GatewayGuardLocalIo::$headers);
        GatewayGuardLocalIo::phase('handshake_read');return $line??false;
    }
    function stream_select(?array &$read,?array &$write,?array &$except,?int $seconds,?int $microseconds=null):int|false
    {
        if($write){++GatewayGuardLocalIo::$writeWaits;GatewayGuardLocalIo::phase('write_wait');return 1;}
        ++GatewayGuardLocalIo::$readWaits;GatewayGuardLocalIo::phase('read_wait');return GatewayGuardLocalIo::$incoming?1:0;
    }
    function fread($stream,int $length):string|false
    {
        ++GatewayGuardLocalIo::$reads;$bytes=array_shift(GatewayGuardLocalIo::$incoming)??'';
        GatewayGuardLocalIo::phase('frame_read');return $bytes;
    }
}
namespace {
require_once __DIR__.'/artifact_directory.php';
require dirname(__DIR__).'/bootstrap.php';require __DIR__.'/fixtures/OperationsRuntimeFixture.php';
use RadioStamp\Core\{Database,FrozenClock};
use RadioStamp\Discord\{DiscordRestClient,GatewayTransport,GuardedGatewayTransport,GatewayRosterSession,GatewayGuardLocalIo,MemberActionFence,ServerOperationsClient,ServerOperationsGateway,TlsGatewayTransport};
use RadioStamp\Operations\SafeDiagnostics;

$checks=0;$evidence=[];$exceptionsOnly=in_array('--exceptions-only',$argv??[],true);
$same=static function(mixed $expected,mixed $actual,string $why)use(&$checks):void{
    ++$checks;if($expected!==$actual){throw new RuntimeException('Gateway guard: '.$why);}
};
class A5Transport implements GatewayTransport
{
    public array $frames,$sent=[],$sentAfterStop=[];
    public int $connects=0,$receives=0,$closes=0;
    public bool $failConnect=false,$throwConnect=false,$throwClose=false,$failSend=false,$throwSend=false;
    public ?Closure $onConnect=null,$onReceive=null,$onSend=null;
    public string $phase='created';
    public function __construct(private Closure $allowed)
    {
        $this->frames=[['op'=>10,'d'=>['heartbeat_interval'=>45000]],['op'=>0,'s'=>1,'t'=>'READY','d'=>['user'=>['id'=>DiscordRestClient::APPLICATION_ID,'bot'=>true]]],
            ['op'=>0,'s'=>2,'t'=>'GUILD_CREATE','d'=>['id'=>DiscordRestClient::GUILD_ID,'voice_states'=>[['user_id'=>'800000000000001021','channel_id'=>DiscordRestClient::VOICE_CHANNEL_ID]]]],['op'=>11]];
    }
    public function connect():bool
    {
        ++$this->connects;$this->phase='connect';if($this->onConnect!==null){($this->onConnect)();}
        if($this->throwConnect){throw new RuntimeException('fictional connect failure');}return !$this->failConnect;
    }
    public function send(#[SensitiveParameter] array $payload):bool
    {
        $op=$payload['op'];$this->sent[]=$op;if(($this->allowed)()!==true){$this->sentAfterStop[]=$op;}
        $this->phase=$op===1?'heartbeat_sent':'identify_sent';if($this->onSend!==null){($this->onSend)($op);}
        if($this->throwSend){throw new RuntimeException('fictional_exception_secret_canary://send');}return !$this->failSend;
    }
    public function receive(float $timeoutSeconds):?array
    {
        ++$this->receives;$payload=array_shift($this->frames);$this->phase=$payload===null?'receive_null':(($payload['op']??null)===10?'hello_returned':'receive_returned');
        if($this->onReceive!==null){($this->onReceive)($payload);}return $payload;
    }
    public function close():void{++$this->closes;$this->phase='closed';if($this->throwClose){throw new RuntimeException('fictional close failure');}}
}
final class A5GuardedTransport extends A5Transport implements GuardedGatewayTransport
{
    public bool $guardInstalled=false;
    public function setGuard(Closure $guard):void{$this->guardInstalled=true;}
    public function connect():bool{if(!$this->guardInstalled){throw new RuntimeException('guard missing before connect');}return parent::connect();}
}

$fixture=static function(?Closure $configure=null,bool $guarded=false)use($same):array{
    $allowed=true;$mono=0.0;$guardHook=null;
    $rawAllowed=static function()use(&$allowed):bool{return $allowed;};
    $transport=$guarded?new A5GuardedTransport($rawAllowed):new A5Transport($rawAllowed);
    $guard=static function()use(&$allowed,&$guardHook,$transport):bool{if($guardHook!==null){return $guardHook($transport,$allowed);}return $allowed;};
    $db=new Database(':memory:');$clock=new FrozenClock('2026-10-20T07:18:00+09:00');$http=new RuntimeFixtureHttp();
    $client=new ServerOperationsClient($db,$http,'fictional_a5_token_canary_000000',['enabled'=>true]);
    $factory=static fn():GatewayTransport=>$transport;
    if($configure!==null){$configure($transport,$allowed,$mono,$guardHook,$factory);}
    $session=new GatewayRosterSession($db,$client,$clock,'fictional_a5_token_canary_000000',$factory,$guard,static function()use(&$mono):float{return $mono;});
    return [$session,$transport,$http,$db,static function(bool $value)use(&$allowed):void{$allowed=$value;},static function(float $value)use(&$mono):void{$mono=$value;},$clock];
};
if(!$exceptionsOnly){
$startupCases=[
    'guard_false_before_start'=>static function($t,&$allowed):void{$allowed=false;},
    'factory_revokes'=>static function($t,&$allowed,&$mono,&$hook,&$factory):void{$factory=static function()use($t,&$allowed){$allowed=false;return $t;};},
    'connect_revokes'=>static function($t,&$allowed):void{$t->onConnect=static function()use(&$allowed):void{$allowed=false;};},
    'hello_revokes'=>static function($t,&$allowed):void{$t->onReceive=static function($payload)use(&$allowed):void{if(($payload['op']??null)===10){$allowed=false;}};},
    'heartbeat_before'=>static function($t,&$allowed,&$mono,&$hook):void{$seen=0;$hook=static function($t,bool &$allowed)use(&$seen):bool{if($t->phase==='hello_returned'&&++$seen>=2){$allowed=false;}return $allowed;};},
    'heartbeat_during'=>static function($t,&$allowed):void{$t->onSend=static function(int $op)use(&$allowed):void{if($op===1){$allowed=false;}};},
    'identify_before'=>static function($t,&$allowed,&$mono,&$hook):void{$seen=0;$hook=static function($t,bool &$allowed)use(&$seen):bool{if($t->phase==='heartbeat_sent'&&++$seen>=2){$allowed=false;}return $allowed;};},
    'identify_during'=>static function($t,&$allowed):void{$t->onSend=static function(int $op)use(&$allowed):void{if($op===2){$allowed=false;}};},
    'startup_null_receive_revokes'=>static function($t,&$allowed):void{$t->onReceive=static function($payload)use(&$allowed):void{if($payload===null){$allowed=false;}};},
    'startup_success_boundary'=>static function($t,&$allowed,&$mono,&$hook):void{$seen=0;$hook=static function($t,bool &$allowed)use(&$seen):bool{if($t->phase==='receive_null'&&++$seen>=2){$allowed=false;}return $allowed;};},
    'guard_exception'=>static function($t,&$allowed,&$mono,&$hook):void{$hook=static function($t):bool{if($t->phase==='hello_returned'){throw new RuntimeException('fictional guard exception');}return true;};},
    'connect_false'=>static function($t):void{$t->failConnect=true;},
    'connect_exception'=>static function($t):void{$t->throwConnect=true;},
    'close_exception'=>static function($t,&$allowed):void{$t->throwClose=true;$t->onReceive=static function($payload)use(&$allowed):void{$allowed=false;};},
];
foreach($startupCases as$name=>$configure){
    [$session,$transport,$http]=$fixture($configure);$same(false,$session->start(),$name.' fails closed');
    $same(false,$session->active(),$name.' inactive');$same(false,$session->roster()->known,$name.' roster unknown');
    $same([],$transport->sentAfterStop,$name.' no op after stopped');$same(0,count($http->memberPatches()),$name.' member PATCH zero');
    $same($name==='guard_false_before_start'?0:true,$name==='guard_false_before_start'?$transport->closes:$transport->closes>=1,$name.' closes acquired transport');
    $expectedOps=in_array($name,['heartbeat_during','identify_before'],true)?[1]:(in_array($name,['identify_during','startup_null_receive_revokes','startup_success_boundary'],true)?[1,2]:[]);
    $same($expectedOps,$transport->sent,$name.' only IO begun before loss may complete');
    if($name==='factory_revokes'){$same(0,$transport->connects,'factory stop prevents connect');}
    if($name==='guard_false_before_start'){$same(0,count($http->calls),'entry stop prevents gateway metadata HTTP');}
    if($name==='connect_revokes'){$same(0,$transport->receives,'connect stop prevents Hello read');}
    if(in_array($name,['heartbeat_during','identify_during'],true)){$same(1,$transport->receives,$name.' no receive after send loses guard');}
    $evidence['session'][$name]=['result'=>false,'opcodes'=>$transport->sent,'after_stop'=>$transport->sentAfterStop,'closed'=>$transport->closes>0,'member_patch'=>count($http->memberPatches())];
}

foreach([false,true]as$guarded){
    [$session,$transport,$http,$db,$setAllowed,$setMono,$clock]=$fixture(null,$guarded);
    $same(true,$session->start(),'normal compatible startup');$same(true,$session->roster()->known,'complete known roster');
    $same(['800000000000001021'],$session->roster()->memberIds,'roster retained');$same([1,2],$transport->sent,'normal heartbeat and identify');
    $same(false,in_array(4,$transport->sent,true),'no voice opcode 4');
    if($guarded){$same(true,$transport->guardInstalled,'optional guard installed before connect');}
    $state=$session->voiceState('800000000000001021');$fence=new MemberActionFence(DiscordRestClient::GUILD_ID,'800000000000001021',DiscordRestClient::VOICE_CHANNEL_ID,$state->generation,$state->observedAt);
    $same(true,$session->matches($fence),'fresh fence matches');$setAllowed(false);
    $same(false,$session->matches($fence),'guard loss invalidates fence');$same(false,$session->active(),'active session closes on guard loss');
    $same(false,$session->roster()->known,'guard loss invalidates cached roster');$same(false,$session->voiceState('800000000000001021')->known,'guard loss invalidates cached voice state');
    $same([], $transport->sentAfterStop,'active loss no new sends');$same(0,count($http->memberPatches()),'active loss member PATCH zero');
    $setAllowed(true);$same(false,$session->start(),'no reconnect in same worker');$same(1,$transport->connects,'one connection attempt');
    $evidence['compatibility'][$guarded?'guarded':'legacy']=['normal_start'=>true,'opcodes'=>$transport->sent,'after_stop'=>$transport->sentAfterStop,'reconnects'=>0];
}
foreach(['null','payload','server_heartbeat','scheduled_heartbeat','active_start','receive_throw']as$case){
    [$session,$t,$http,$db,$setAllowed,$setMono]=$fixture();$same(true,$session->start(),$case.' startup');
    if($case==='scheduled_heartbeat'||$case==='active_start'){$setMono(45);$setAllowed(false);}
    elseif($case==='receive_throw'){$t->onReceive=static function():void{throw new RuntimeException('fictional receive failure');};}
    else{$t->frames=$case==='payload'?[['op'=>0,'s'=>3,'t'=>'VOICE_STATE_UPDATE','d'=>['guild_id'=>DiscordRestClient::GUILD_ID,'user_id'=>'800000000000001021','channel_id'=>null]]]:($case==='server_heartbeat'?[['op'=>1]]:[]);$t->onReceive=static function()use($setAllowed):void{$setAllowed(false);};}
    $same(false,$case==='active_start'?$session->start():$session->pump(0.1),$case.' active operation fails closed');$same(false,$session->active(),$case.' inactive');
    $same(false,$session->roster()->known,$case.' unknown roster');$same([1,2],$t->sent,$case.' no additional heartbeat');
    $same([], $t->sentAfterStop,$case.' no frames after stop');$same(true,$t->closes>=1,$case.' transport closed');$same(0,count($http->memberPatches()),$case.' member PATCH zero');
    $evidence['active'][$case]=['result'=>false,'after_stop'=>$t->sentAfterStop,'closed'=>true,'member_patch'=>0];
}
foreach([9.999,10.0,10.001]as$boundary){
    [$session,$t,$http]=$fixture(static function($t,&$allowed,&$mono,&$hook)use($boundary):void{$mono=$boundary;$hook=static function()use(&$mono):bool{return $mono<10;};});
    $expected=$boundary<10;$same($expected,$session->start(),'monotonic deadline boundary '.$boundary);
    $same($expected?[1,2]:[],$t->sent,'exact deadline denies sends');$same(0,count($http->memberPatches()),'boundary member PATCH zero');$session->close();
    $evidence['deadline'][(string)$boundary]=['start'=>$expected,'opcodes'=>$t->sent];
}
foreach(['connect','hello']as$phase){foreach([9.999,10.0,10.001]as$boundary){
    [$session,$t,$http]=$fixture(static function($t,&$allowed,&$mono,&$hook)use($phase,$boundary):void{
        $mono=9.5;$hook=static function()use(&$mono):bool{return $mono<10;};
        $expire=static function()use(&$mono,$boundary):void{$mono=$boundary;};
        if($phase==='connect'){$t->onConnect=$expire;}else{$t->onReceive=static function($payload)use($expire):void{if(($payload['op']??null)===10){$expire();}};}
    });
    $expected=$boundary<10;$same($expected,$session->start(),$phase.' exact deadline after wait');
    $same($expected?[1,2]:[],$t->sent,$phase.' deadline has no later frames');$same(0,count($http->memberPatches()),$phase.' deadline member PATCH zero');
    if(!$expected){$same(true,$t->closes>=1,$phase.' deadline closes transport');}
    $session->close();$evidence['deadline'][$phase.'_'.$boundary]=['start'=>$expected,'opcodes'=>$t->sent];
}}
foreach(['heartbeat_pre_send','active_guard_exception']as$case){
    [$session,$t,$http,$db,$setAllowed,$setMono]=$fixture(static function($t,&$allowed,&$mono,&$hook)use($case):void{
        $seen=0;$hook=static function($t,bool &$allowed)use(&$mono,&$seen,$case):bool{
            if($mono>=45){if($case==='active_guard_exception'){throw new RuntimeException('fictional active guard exception');}if(++$seen>=2){$allowed=false;}}
            return $allowed;
        };
    });
    $same(true,$session->start(),$case.' startup');$setMono(45);$same(false,$session->pump(),$case.' fails closed');
    $same([1,2],$t->sent,$case.' scheduled heartbeat suppressed');$same(false,$session->roster()->known,$case.' roster unknown');
    $same(true,$t->closes>=1,$case.' closes transport');$same(0,count($http->memberPatches()),$case.' member PATCH zero');
    $evidence['active'][$case]=['result'=>false,'opcodes'=>$t->sent,'closed'=>true,'member_patch'=>0];
}

// Existing budgets are conservative reservations, even when a known-unsent attempt stops.
[$session,$t,$http,$db]=$fixture();$same(true,$session->start(),'budget baseline');$session->close();
$clock=new FrozenClock('2026-10-20T07:18:00+09:00');$client=new ServerOperationsClient($db,$http,'fictional_a5_token_canary_000000',['enabled'=>true]);
$cap=static function()use($db,$clock,$client):array{$t=new A5Transport(static fn()=>true);return [new GatewayRosterSession($db,$client,$clock,'fictional_a5_token_canary_000000',static fn()=>$t,static fn()=>true),$t];};
[$blocked,$blockedT]=$cap();$same(false,$blocked->start(),'six second identify spacing');$same(0,$blockedT->connects,'spacing blocks connect');
$clock->advance(6);$db->pdo()->exec('UPDATE gateway_closure_attempts SET attempts=3');[$blocked,$blockedT]=$cap();
$same(false,$blocked->start(),'closure identify cap preserved');$same(0,$blockedT->connects,'closure cap blocks connect');
$db->pdo()->exec('UPDATE gateway_closure_attempts SET attempts=0');$db->pdo()->exec('UPDATE gateway_identify_budget SET attempts=20');[$blocked,$blockedT]=$cap();
$same(false,$blocked->start(),'daily identify cap preserved');$same(0,$blockedT->connects,'daily cap blocks connect');

$server=static function(string $text,int $opcode=1):string{return chr(128|$opcode).chr(strlen($text)).$text;};
$socketOpen=static function(TlsGatewayTransport $tls):bool{return $tls->diagnostics()['socket_open'];};
foreach(['before_connect','connect','handshake_write','handshake_read','handshake_ready','exception']as$phase){
    GatewayGuardLocalIo::reset();$allowed=$phase!=='before_connect';$tls=new TlsGatewayTransport();
    $tls->setGuard(static function()use(&$allowed,$phase):bool{if($phase==='exception'){throw new RuntimeException('fictional TLS guard exception');}return $allowed;});
    GatewayGuardLocalIo::$hook=static function(string $seen)use(&$allowed,$phase):void{if($seen===$phase){$allowed=false;}};
    $same(false,$tls->connect(),'TLS '.$phase.' stops connect');$same(false,$socketOpen($tls),'TLS '.$phase.' closes socket');
    $same(in_array($phase,['before_connect','exception'],true)?0:1,GatewayGuardLocalIo::$connects,'TLS '.$phase.' connect count');
    $same(in_array($phase,['handshake_write','handshake_read','handshake_ready'],true)?1:0,GatewayGuardLocalIo::$writes,'TLS '.$phase.' handshake write boundary');
    $same($phase==='handshake_ready'?5:($phase==='handshake_read'?1:0),GatewayGuardLocalIo::$headerReads,'TLS '.$phase.' no later header read');
    $evidence['tls_connect'][$phase]=['connect_calls'=>GatewayGuardLocalIo::$connects,'writes'=>GatewayGuardLocalIo::$writes,'header_reads'=>GatewayGuardLocalIo::$headerReads,'closed'=>true];
}
foreach(['send_entry','write_wait','frame_write','receive_entry','read_wait','read_ready_wait','frame_read','pong']as$phase){
    GatewayGuardLocalIo::reset();$allowed=true;$tls=new TlsGatewayTransport();$tls->setGuard(static function()use(&$allowed):bool{return $allowed;});
    $same(true,$tls->connect(),'TLS local fake handshake baseline');
    $writes=GatewayGuardLocalIo::$writes;$reads=GatewayGuardLocalIo::$reads;
    if($phase==='send_entry'||$phase==='receive_entry'){$allowed=false;}
    GatewayGuardLocalIo::$hook=static function(string $seen)use(&$allowed,$phase):void{if($seen===($phase==='read_ready_wait'?'read_wait':$phase)){$allowed=false;}};
    if(in_array($phase,['send_entry','write_wait','frame_write'],true)){$same(false,$tls->send(['op'=>1,'d'=>null]),'TLS send loss fails');}
    else{
        if($phase==='frame_read'||$phase==='read_ready_wait'){GatewayGuardLocalIo::$incoming=[$server('{"op":11}')];}
        if($phase==='pong'){
            $property=new ReflectionProperty(TlsGatewayTransport::class,'pending');$property->setValue($tls,[['opcode'=>9,'payload'=>'fictional-ping']]);
            GatewayGuardLocalIo::$hook=static function(string $seen)use(&$allowed):void{if($seen==='write_wait'){$allowed=false;}};
        }
        $thrown=false;try{$tls->receive(0.1);}catch(Throwable){$thrown=true;}$same(true,$thrown,'TLS receive loss throws');
    }
    $same(false,$socketOpen($tls),'TLS '.$phase.' stopped socket closed');
    $same($phase==='frame_write'?1:0,GatewayGuardLocalIo::$writes-$writes,'TLS '.$phase.' no write begun after stop');
    $same($phase==='frame_read'?1:0,GatewayGuardLocalIo::$reads-$reads,'TLS '.$phase.' no read begun after stop');
    $same($phase==='frame_write'?[1]:[],GatewayGuardLocalIo::$opcodes,'TLS '.$phase.' Pong suppressed after wait');
    $evidence['tls_io'][$phase]=['frame_writes'=>GatewayGuardLocalIo::$writes-$writes,'reads'=>GatewayGuardLocalIo::$reads-$reads,'opcodes'=>GatewayGuardLocalIo::$opcodes,'closed'=>true];
}
GatewayGuardLocalIo::reset();$tls=new TlsGatewayTransport();$same(true,$tls->connect(),'TLS optional guard absent remains compatible');
GatewayGuardLocalIo::$incoming=[$server('fictional-ping',9).$server('{"op":11}')];
$same(['op'=>11],$tls->receive(0.1),'TLS normal Ping then payload');$same([10],GatewayGuardLocalIo::$opcodes,'TLS normal Pong maintained');$tls->close();
$same(false,$socketOpen($tls),'TLS normal close');
$evidence['tls_normal']=['fake_handshake'=>true,'pong_opcodes'=>GatewayGuardLocalIo::$opcodes,'payload_returned'=>true,'closed'=>true];
GatewayGuardLocalIo::reset();$tls=new TlsGatewayTransport();$same(true,$tls->connect(),'TLS invalid payload setup');$writes=GatewayGuardLocalIo::$writes;
$same(false,$tls->send(['op'=>1,'d'=>NAN]),'TLS encode exception fails closed');$same(false,$socketOpen($tls),'TLS encode exception closes socket');
$same($writes,GatewayGuardLocalIo::$writes,'TLS encode exception has zero frame writes');
$evidence['tls_encode_exception']=['result'=>false,'frame_writes'=>0,'closed'=>true];
}

$exceptionCases=[
    'guard_stopped'=>['gateway_guard_stopped',static function($t,&$allowed):void{$t->onConnect=static function()use(&$allowed):void{$allowed=false;};}],
    'guard_exception'=>['gateway_guard_exception',static function($t,&$allowed,&$mono,&$hook):void{$hook=static function($t):bool{if($t->phase==='connect'){throw new RuntimeException('fictional_exception_secret_canary://guard');}return true;};}],
    'connect_false'=>['gateway_connect_failed',static function($t):void{$t->failConnect=true;}],
    'connect_throw'=>['gateway_connect_failed',static function($t):void{$t->throwConnect=true;}],
    'hello_malformed'=>['gateway_hello_invalid',static function($t):void{$t->frames=[['op'=>10,'d'=>['heartbeat_interval'=>'45000']]];}],
    'send_false'=>['gateway_send_failed',static function($t):void{$t->failSend=true;}],
    'send_throw'=>['gateway_send_failed',static function($t):void{$t->throwSend=true;}],
    'heartbeat_missing_ack'=>['gateway_heartbeat_lost',static function($t,&$allowed,&$mono):void{$t->frames=array_values(array_filter($t->frames,static fn($p):bool=>($p['op']??null)!==11));$t->onReceive=static function($payload)use(&$mono):void{if($payload===null){$mono+=1;}};}],
    'receive_throw'=>['gateway_exception',static function($t):void{$t->onReceive=static function():void{throw new RuntimeException('fictional_exception_secret_canary://receive');};}],
    'close_throw'=>['gateway_guard_stopped',static function($t,&$allowed):void{$t->throwClose=true;$t->onConnect=static function()use(&$allowed):void{$allowed=false;};}],
    'identify_cap'=>['gateway_identify_limited',null],
];
foreach($exceptionCases as$name=>[$expected,$configure]){
    [$session,$t,$http,$db,$setAllowed,$setMono,$clock]=$fixture($configure);
    if($name==='identify_cap'){$db->pdo()->prepare('INSERT INTO gateway_closure_attempts VALUES(?,3)')->execute(['probe-'.gmdate('Y-m-d',$clock->now()->getTimestamp())]);}
    $lines=[];$config=['diagnostics_enabled'=>true,'participant_scope'=>'allowlist','participant_user_ids'=>['800000000000001021']];
    $diagnostics=SafeDiagnostics::fromConfig($config,static function(string $line)use(&$lines):bool{$lines[]=$line;return true;});
    $client=new ServerOperationsClient($db,$http,'fictional_a5_token_canary_000000',['enabled'=>true]);
    $gateway=new ServerOperationsGateway($client,$clock,$config,$session,$diagnostics);
    $same(false,$gateway->roster(DiscordRestClient::GUILD_ID,DiscordRestClient::VOICE_CHANNEL_ID)->known,$name.' normal adapter fails closed');
    $same($expected,$session->failureCode(),$name.' fixed failure retained');$same(false,$session->active(),$name.' inactive');
    $same(false,$session->roster()->known,$name.' cached roster unknown');$same(0,count($http->memberPatches()),$name.' member PATCH zero');
    $codes=array_column(array_map(static fn(string $line):array=>json_decode($line,true,flags:JSON_THROW_ON_ERROR),$lines),'code');
    $same(true,in_array('roster_unknown',$codes,true),$name.' preserves existing log');$same(true,in_array($expected,$codes,true),$name.' fixed cause logged');
    $same($name==='close_throw',$session->closeFailed(),$name.' close outcome observable');
    if($name==='close_throw'){$same(true,in_array('gateway_close_failed',$codes,true),'cleanup failure fixed code logged');}
    $session->close();$same($expected,$session->failureCode(),$name.' close preserves cause');
    $log=implode('',$lines);$same(false,str_contains($log,'fictional_exception_secret_canary'),$name.' exception message hidden');
    $same(false,str_contains($log,'fictional_a5_token_canary'),$name.' credential canary hidden');$same(false,str_contains($log,'://'),$name.' URL hidden');
    $same(false,str_contains($log,'other_code'),$name.' fixed causes accepted by diagnostics');
    $evidence['exceptions'][$name]=['cause'=>$expected,'log_codes'=>$codes,'result'=>false,'inactive'=>true,'member_patch'=>0,'cleanup_failed'=>$session->closeFailed()];
    $setAllowed(true);$same(false,$session->start(),$name.' same worker retry remains blocked');
    $same('gateway_identify_limited',$session->failureCode(),$name.' next start replaces prior failure');$same(false,$session->closeFailed(),$name.' next start resets cleanup flag');
}
foreach(['pump_exception','matches_exception','pump_guard']as$name){
    [$session,$t,$http,$db,$setAllowed,$setMono,$clock]=$fixture();$lines=[];
    $config=['diagnostics_enabled'=>true,'participant_scope'=>'allowlist','participant_user_ids'=>['800000000000001021']];
    $diagnostics=SafeDiagnostics::fromConfig($config,static function(string $line)use(&$lines):bool{$lines[]=$line;return true;});
    $client=new ServerOperationsClient($db,$http,'fictional_a5_token_canary_000000',['enabled'=>true]);
    $gateway=new ServerOperationsGateway($client,$clock,$config,$session,$diagnostics);
    $same(true,$gateway->roster(DiscordRestClient::GUILD_ID,DiscordRestClient::VOICE_CHANNEL_ID)->known,$name.' normal adapter startup');$lines=[];
    $state=$session->voiceState('800000000000001021');$fence=new MemberActionFence(DiscordRestClient::GUILD_ID,'800000000000001021',DiscordRestClient::VOICE_CHANNEL_ID,$state->generation,$state->observedAt);
    if($name==='pump_guard'){$setAllowed(false);}else{$t->onReceive=static function():void{throw new RuntimeException('fictional_exception_secret_canary://active');};}
    $same(false,$name==='matches_exception'?$session->matches($fence):$session->pump(),$name.' direct active failure');
    $expected=$name==='pump_guard'?'gateway_guard_stopped':'gateway_exception';
    $codes=array_column(array_map(static fn(string $line):array=>json_decode($line,true,flags:JSON_THROW_ON_ERROR),$lines),'code');
    $same([$expected],$codes,$name.' cause immediately logged before another roster/start');
    $same(false,$session->active(),$name.' inactive');$same(false,$session->roster()->known,$name.' cached roster unknown');
    $same(0,count($http->memberPatches()),$name.' member PATCH zero');
    $setAllowed(true);$same(false,$gateway->roster(DiscordRestClient::GUILD_ID,DiscordRestClient::VOICE_CHANNEL_ID)->known,$name.' same-worker retry remains blocked');
    $codes=array_column(array_map(static fn(string $line):array=>json_decode($line,true,flags:JSON_THROW_ON_ERROR),$lines),'code');
    $same($expected,$codes[0],$name.' first cause survives next start reset in log');$same(true,in_array('gateway_identify_limited',$codes,true),$name.' retry limit also logged');
    $same(false,str_contains(implode('',$lines),'fictional_exception_secret_canary'),$name.' exception text hidden');
    $evidence['active_diagnostics'][$name]=['first_code'=>$expected,'log_codes'=>$codes,'immediate'=>true,'member_patch'=>0];
}
[$session,$t,$http]=$fixture(static function($t):void{$t->failConnect=true;});
$session->setFailureReporter(static function(string $code):void{throw new RuntimeException('fictional diagnostic sink failure');});
$same(false,$session->start(),'throwing failure reporter does not escape');$same('gateway_connect_failed',$session->failureCode(),'throwing reporter preserves fixed cause');
$same(false,$session->active(),'throwing reporter inactive');$same(false,$session->roster()->known,'throwing reporter roster unknown');
$same(0,count($http->memberPatches()),'throwing reporter member PATCH zero');
$evidence['diagnostic_sink_failure']=['result'=>false,'cause'=>$session->failureCode(),'inactive'=>true,'member_patch'=>0];

$encoded=json_encode($evidence,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR);
$same(false,str_contains($encoded,'fictional_a5_token_canary'),'artifact excludes token canary');
$same(false,str_contains($encoded,'Sec-WebSocket'),'artifact excludes handshake details');
if(!$exceptionsOnly){$same(false,str_contains(print_r($tls,true),'fictional_a5_token_canary'),'TLS debug excludes token canary');}
$source=[];foreach(['src/Discord/GatewayRosterSession.php','src/Discord/TlsGatewayTransport.php','src/Discord/GuardedGatewayTransport.php','src/Discord/ServerOperationsGateway.php','tests/gateway_guard_recovery.php']as$file){$source[$file]=hash_file('sha256',dirname(__DIR__).'/'.$file);}
$artifact=['test'=>'gateway_guard_recovery','variant'=>$exceptionsOnly?'exceptions_only':'all','assertions'=>$checks,'network'=>'none: fake HTTP/Gateway and PHP I/O doubles with local temp resources only','source_sha256'=>$source,'cases'=>$evidence,
    'limitations'=>['A bool guard cannot interrupt blocking I/O already begun.','The interval between the last guard check and an I/O call is not atomic.','No real TLS/WSS or live member disconnect was tested.']];
$directory=getenv('RADIO_STAMP_TEST_ARTIFACT_DIR');$directory=is_string($directory)&&$directory!==''?$directory:getenv('RADIO_STAMP_TEST_ARTIFACT_DIR');
if(!is_dir($directory)&&!mkdir($directory,0777,true)&&!is_dir($directory)){throw new RuntimeException('artifact directory unavailable');}
file_put_contents($directory.($exceptionsOnly?'/gateway-guard-exceptions.safe.json':'/gateway-guard.safe.json'),json_encode($artifact,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR)."\n");
echo "Gateway guard recovery: {$checks} assertions passed (fake/local I/O only; no external connection).\n";
}
