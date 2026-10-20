<?php
declare(strict_types=1);
namespace RadioStamp\Discord;

use PDO;
use RadioStamp\Core\{Database,Clock};

/** Bounded data-only Gateway connection, opened lazily for closure; never sends voice opcode 4. */
final class GatewayRosterSession implements VoiceRosterSource
{
    private GatewayVoiceTracker $tracker;private ?GatewayTransport $transport=null;
    private bool $attempted=false;private bool $connected=false;private bool $ready=false;private bool $ack=false;
    private ?int $sequence=null;private float $interval=45;private float $nextHeartbeat=0;private ?float $awaitingSince=null;
    private array $versions=[];private int $epoch;private \Closure $monotonic;
    private ?string $failure=null;private bool $cleanupFailed=false;private ?\Closure $failureReporter=null;
    public function __construct(private Database $db,private ServerOperationsClient $client,private Clock $clock,
        #[\SensitiveParameter] private string $botToken,private \Closure $transportFactory,private \Closure $guard,?callable $monotonic=null)
    {
        $this->tracker=new GatewayVoiceTracker($clock,120);$this->epoch=random_int(1,1000000000);
        $this->monotonic=$monotonic===null?static fn():float=>hrtime(true)/1e9:\Closure::fromCallable($monotonic);
        $db->pdo()->exec('CREATE TABLE IF NOT EXISTS gateway_identify_budget(day TEXT PRIMARY KEY,attempts INTEGER NOT NULL,last_attempt REAL NOT NULL)');
        $db->pdo()->exec('CREATE TABLE IF NOT EXISTS gateway_closure_attempts(session_id TEXT PRIMARY KEY,attempts INTEGER NOT NULL)');
    }
    public function __debugInfo():array{return ['connected'=>$this->connected,'ready'=>$this->ready,'credentials'=>'[redacted]','voice_connection'=>false];}
    public function active():bool{return $this->connected;}
    public function failureCode():?string{return $this->failure;}
    public function closeFailed():bool{return $this->cleanupFailed;}
    public function setFailureReporter(\Closure $reporter):void
    {
        $this->failureReporter=$reporter;
        if($this->failure!==null){$this->notifyFailure($this->failure);}
        if($this->cleanupFailed){$this->notifyFailure('gateway_close_failed');}
    }
    private function notifyFailure(string $code):void
    {
        try{if($this->failureReporter!==null){($this->failureReporter)($code);}}catch(\Throwable){}
    }
    private function rememberFailure(string $code):void{$this->failure=$code;$this->notifyFailure($code);}
    private function fail(string $code):never{$this->rememberFailure($code);throw new \RuntimeException('gateway_operation_failed');}
    private function requireRunning():void
    {
        try{$allowed=($this->guard)()===true;}catch(\Throwable){$this->fail('gateway_guard_exception');}
        if(!$allowed){$this->fail('gateway_guard_stopped');}
    }
    private function send(#[\SensitiveParameter] array $payload):void
    {
        $this->requireRunning();
        try{$sent=$this->transport?->send($payload);}catch(\Throwable){$this->fail('gateway_send_failed');}
        if(!$sent){$this->requireRunning();$this->fail('gateway_send_failed');}
        $this->requireRunning();
    }
    public function start():bool
    {
        $this->failure=null;$this->cleanupFailed=false;
        try{
            $this->requireRunning();
            if($this->connected){$known=$this->pump()&&$this->tracker->snapshot(DiscordRestClient::GUILD_ID)->known;$this->requireRunning();return $known;}
            if($this->attempted){$this->rememberFailure('gateway_identify_limited');return false;}$this->attempted=true;
            $info=$this->client->gatewayInfo();if(!$info->ok){return false;}$this->requireRunning();
            $now=$this->clock->now()->getTimestamp();$day=gmdate('Y-m-d',$now);
            $budget=$this->db->transaction(function(PDO $pdo)use($now,$day):bool{
                $session=$pdo->query("SELECT id FROM sessions WHERE status='closing' ORDER BY scheduled_at DESC LIMIT 1")->fetchColumn();$session=is_string($session)?$session:'probe-'.$day;
                $q=$pdo->prepare('SELECT attempts FROM gateway_closure_attempts WHERE session_id=?');$q->execute([$session]);
                if((int)$q->fetchColumn()>=3){return false;}
                $q=$pdo->prepare('SELECT attempts,last_attempt FROM gateway_identify_budget WHERE day=?');$q->execute([$day]);$row=$q->fetch();
                if($row!==false&&((int)$row['attempts']>=20||$now-(float)$row['last_attempt']<6)){return false;}
                $pdo->prepare('INSERT INTO gateway_identify_budget(day,attempts,last_attempt) VALUES(?,1,?) ON CONFLICT(day) DO UPDATE SET attempts=attempts+1,last_attempt=excluded.last_attempt')->execute([$day,$now]);
                $pdo->prepare('INSERT INTO gateway_closure_attempts(session_id,attempts) VALUES(?,1) ON CONFLICT(session_id) DO UPDATE SET attempts=attempts+1')->execute([$session]);return true;
            });
            if(!$budget){$this->rememberFailure('gateway_identify_limited');return false;}$this->requireRunning();
            $transport=($this->transportFactory)();if(!$transport instanceof GatewayTransport){$this->fail('gateway_exception');}$this->transport=$transport;
            if($transport instanceof GuardedGatewayTransport){$transport->setGuard($this->guard);}
            $this->requireRunning();try{$connected=$transport->connect();}catch(\Throwable){$this->fail('gateway_connect_failed');}
            if(!$connected){$this->requireRunning();$this->fail('gateway_connect_failed');}$this->requireRunning();
            $hello=$transport->receive(2);$this->requireRunning();$milliseconds=$hello['d']['heartbeat_interval']??null;
            if(($hello['op']??null)!==10||!is_int($milliseconds)||$milliseconds<1000||$milliseconds>120000){$this->fail('gateway_hello_invalid');}
            $this->interval=$milliseconds/1000;$this->connected=true;$this->nextHeartbeat=($this->monotonic)();
            $this->heartbeat(true);
            $this->send(['op'=>2,'d'=>['token'=>$this->botToken,'intents'=>129,'compress'=>false,'large_threshold'=>50,
                'properties'=>['os'=>'linux','browser'=>'radio-stamp','device'=>'radio-stamp']]]);
            $end=($this->monotonic)()+8;
            while(($this->monotonic)()<$end){
                $this->requireRunning();
                if(!$this->pump(0.1)){return false;}
                if($this->ack&&$this->ready&&$this->tracker->snapshot(DiscordRestClient::GUILD_ID)->known){$this->requireRunning();return true;}
            }
        }catch(\Throwable){if($this->failure===null){$this->rememberFailure('gateway_exception');}}
        if($this->failure===null){$this->rememberFailure('gateway_exception');}$this->close();return false;
    }
    private function heartbeat(bool $scheduled):void
    {
        $now=($this->monotonic)();
        if($this->awaitingSince!==null&&$now-$this->awaitingSince>5){$this->fail('gateway_heartbeat_lost');}
        $this->send(['op'=>1,'d'=>$this->sequence]);
        $this->awaitingSince??=$now;if($scheduled){$this->nextHeartbeat=$now+$this->interval;}
    }
    public function pump(float $wait=0):bool
    {
        if(!$this->connected||$this->transport===null){return false;}
        try{
            $this->requireRunning();
            $now=($this->monotonic)();if($this->awaitingSince!==null&&$now-$this->awaitingSince>5){$this->fail('gateway_heartbeat_lost');}
            if($now>=$this->nextHeartbeat){$this->heartbeat(true);}
            for($i=0;$i<128;$i++){
                $this->requireRunning();$payload=$this->transport->receive($i===0?$wait:0);$this->requireRunning();if($payload===null){return true;}
                $op=$payload['op']??null;
                if($op===11){$this->awaitingSince=null;$this->ack=true;$this->tracker->heartbeatAcknowledged();continue;}
                if($op===1){$this->heartbeat(false);continue;}
                if($op!==0||!is_int($payload['s']??null)||!is_string($payload['t']??null)||!is_array($payload['d']??null)){throw new \RuntimeException('gateway_dispatch_invalid');}
                $event=$payload['t'];$data=$payload['d'];$sequence=$payload['s'];
                if($event==='READY'){
                    if($this->ready||($data['user']['id']??null)!==DiscordRestClient::APPLICATION_ID||($data['user']['bot']??null)!==true
                        ||isset($data['application']['id'])&&$data['application']['id']!==DiscordRestClient::APPLICATION_ID){throw new \RuntimeException('gateway_identity_invalid');}
                    $this->ready=true;$safe=[];
                }elseif($event==='GUILD_CREATE'&&($data['id']??null)===DiscordRestClient::GUILD_ID){
                    $safe=['id'=>$data['id'],'unavailable'=>$data['unavailable']??false,'voice_states'=>$data['voice_states']??null];
                    if(!is_array($safe['voice_states'])){throw new \RuntimeException('gateway_snapshot_incomplete');}
                    $safe['voice_states']=array_map(static fn(array $state):array=>array_intersect_key($state,array_flip(['user_id','channel_id'])),$safe['voice_states']);
                    foreach($safe['voice_states']as$state){$this->versions[$state['user_id']??'invalid']=++$this->epoch;}
                }elseif($event==='VOICE_STATE_UPDATE'&&($data['guild_id']??null)===DiscordRestClient::GUILD_ID){
                    $safe=array_intersect_key($data,array_flip(['guild_id','user_id','channel_id']));$this->versions[$data['user_id']??'invalid']=++$this->epoch;
                }elseif($event==='GUILD_DELETE'&&($data['id']??null)===DiscordRestClient::GUILD_ID){$safe=['id'=>$data['id']];}
                else{$event='UNRELATED';$safe=[];}
                $this->sequence=$sequence;$accepted=$this->tracker->onDispatch($event,$sequence,$safe);
                if(!$accepted['ok']){throw new \RuntimeException('gateway_projection_unknown');}
            }
            throw new \RuntimeException('gateway_dispatch_budget');
        }catch(\Throwable){if($this->failure===null){$this->rememberFailure('gateway_exception');}$this->close();return false;}
    }
    public function voiceState(string $user):VoiceState
    {
        $state=$this->tracker->voiceState(DiscordRestClient::GUILD_ID,$user);
        return new VoiceState($state->known,$state->channelId,$state->observedAt,$this->versions[$user]??$this->epoch);
    }
    public function roster():VoiceRoster{return $this->tracker->roster(DiscordRestClient::GUILD_ID,DiscordRestClient::VOICE_CHANNEL_ID);}
    public function matches(MemberActionFence $fence):bool
    {
        if(!$this->pump()||$fence->guildId!==DiscordRestClient::GUILD_ID||$fence->sourceChannelId!==DiscordRestClient::VOICE_CHANNEL_ID){return false;}
        $state=$this->voiceState($fence->userId);
        return $state->known&&$state->channelId===$fence->sourceChannelId&&$state->generation===$fence->generation
            &&$this->clock->now()->getTimestamp()-$fence->observedAt>=0&&$this->clock->now()->getTimestamp()-$fence->observedAt<=5;
    }
    public function close():void
    {
        $transport=$this->transport;$this->transport=null;$this->connected=false;$this->ready=false;$this->ack=false;
        $this->sequence=null;$this->awaitingSince=null;$this->tracker->onDisconnect();$this->versions=[];
        try{$transport?->close();}catch(\Throwable){$this->cleanupFailed=true;$this->notifyFailure('gateway_close_failed');}
    }
    public function __destruct(){$this->close();}
}
