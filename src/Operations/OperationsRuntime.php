<?php
declare(strict_types=1);
namespace RadioStamp\Operations;

use RadioStamp\Core\{Database,Clock,SystemClock,RadioService,WeeklyRanking};
use RadioStamp\Discord\{HttpAdapter,CurlHttpAdapter,DiscordRestClient,ServerOperationsClient,ServerOperationsGateway,GatewayRosterSession,TlsGatewayTransport,MemberActionFence};
use RadioStamp\Http\{InteractionQueue,InteractionWorker,DiscordRestFollowupSink};
use RadioStamp\Cards\CardRenderer;
use RadioStamp\Security\TokenCipher;

/** All runtime wiring is explicit. Constructors do not make HTTP or WSS requests. */
final class OperationsRuntime
{
    private Clock $clock;
    public function __construct(private ?HttpAdapter $http=null,private ?\Closure $transportFactory=null,?Clock $clock=null){$this->clock=$clock??new SystemClock();}
    public function clock():Clock{return $this->clock;}
    public function build(#[\SensitiveParameter] array $config,\Closure $outerGuard,?float $deadline=null,?SafeDiagnostics $diagnostics=null):OperationsContext
    {
        if(!OperationsConfig::ready($config)){throw new \RuntimeException('operations_configuration_unavailable');}
        $diagnostics??=SafeDiagnostics::fromConfig($config);
        $db=new Database($config['private_db_path'],200);$http=$this->http??new CurlHttpAdapter();$session=null;
        $guard=static function()use($outerGuard,&$session):bool{
            if($outerGuard()!==true){return false;}if($session?->active()){$session->pump();}return true;
        };
        // Capture the session by reference, not its initial null value.
        $sourceGuard=static function(string $user,?MemberActionFence $fence)use(&$session):bool{return $fence!==null&&$fence->userId===$user&&$session?->matches($fence)===true;};
        $client=new ServerOperationsClient($db,$http,$config['bot_token'],[
            'enabled'=>true,'posts_enabled'=>$config['posts_enabled'],'roles_enabled'=>$config['roles_enabled'],
            'room_permissions_enabled'=>$config['room_permissions_enabled'],'room_baseline'=>$config['room_baseline'],
            'commands_enabled'=>$config['command_registration_enabled'],'participant_scope'=>ParticipantScope::mode($config),'member_actions_enabled'=>$config['member_actions_enabled'],
            'accept_voice_race_risk'=>$config['accept_voice_race_risk'],'role_ids'=>$config['role_ids'],'owner_user_id'=>$config['owner_user_id']??null,'request_guard'=>$guard,'source_guard'=>$sourceGuard,'diagnostics'=>$diagnostics,
            'weekly_ranking_enabled'=>$config['weekly_ranking_enabled']??false,'weekly_ranking_now'=>fn():int=>$this->clock->now()->getTimestamp()]);
        $session=new GatewayRosterSession($db,$client,$this->clock,$config['bot_token'],$this->transportFactory??static fn()=>new TlsGatewayTransport(),$outerGuard);
        $gateway=new ServerOperationsGateway($client,$this->clock,$config,$session,$diagnostics);$service=new RadioService($db,$gateway,$this->clock,$config);
        $queue=new InteractionQueue($db->pdo(),new TokenCipher($config['token_encryption_key_hex']));
        $queue->initializeAcknowledgements();$handoff=new AckHandoff($config);
        $options=['enabled'=>true];if($deadline!==null){$options['deadline_monotonic']=$deadline;}
        $followup=new DiscordRestFollowupSink(new DiscordRestClient($http,DiscordRestClient::APPLICATION_ID,$config['bot_token'],$options),$diagnostics);
        $preflight=static function()use($client):array{$ok=$client->identity()->ok;return ['ok'=>$ok,'code'=>$ok?'operations_identity_verified':'validation_bot_identity_unverified'];};
        $worker=new InteractionWorker($queue,$service,static fn()=>new CardRenderer($config['font_candidates']),$followup,$this->clock,null,'operations',$preflight,$diagnostics);
        $weeklyRanking=($config['weekly_ranking_enabled']??false)===true?new WeeklyRanking($db,$gateway,$this->clock,$config):null;
        return new OperationsContext($db,$this->clock,$client,$service,$session,$queue,$worker,$config['closure_policy'],$diagnostics,$handoff,$weeklyRanking);
    }
    /** Called solely by EndpointDelivery after a successful ACK finish. */
    public function acknowledgeAndRun(#[\SensitiveParameter] array $config,string $id,AckHandoff $handoff,array $ticket,?SafeDiagnostics $diagnostics=null):array
    {
        if(!$handoff->recordFinished($ticket,$this->clock->now()->getTimestamp())){return ['ok'=>false,'code'=>'interaction_expired'];}
        $diagnostics?->record('ack_handoff',['ok'=>true,'code'=>'ack_handoff_saved']);
        $end=hrtime(true)/1e9+10;$context=null;
        try{
            $context=$this->build($config,static fn():bool=>hrtime(true)/1e9<$end,$end,$diagnostics);
            $context->queue->expire($this->clock->now()->getTimestamp());$recovery=$handoff->recover($context->queue,$this->clock->now()->getTimestamp());
            if($recovery['recovered']>0){$diagnostics?->record('ack_handoff',['ok'=>true,'code'=>'ack_handoff_recovered','count'=>$recovery['recovered']]);}
            if(!$context->queue->acknowledged($id)){$diagnostics?->record('ack_handoff',['ok'=>false,'code'=>'ack_handoff_pending']);return ['ok'=>false,'code'=>'ack_handoff_pending'];}
            return $context->worker->runOnce($id)??['code'=>'operations_no_pending_job'];
        }catch(\Throwable $error){$diagnostics?->exception('ack_handoff',$error);$diagnostics?->record('ack_handoff',['ok'=>false,'code'=>'ack_handoff_pending']);return ['ok'=>false,'code'=>'ack_handoff_pending'];}
        finally{$context?->roster->close();}
    }
    public function cycle(OperationsContext $context,\Closure $guard):array
    {
        $expired=$context->queue->expire($this->clock->now()->getTimestamp());
        if($expired>0){$context->diagnostics?->record('ack_handoff',['ok'=>true,'code'=>'interaction_expired','count'=>$expired]);}
        $recovery=$context->handoff?->recover($context->queue,$this->clock->now()->getTimestamp());
        if(($recovery['recovered']??0)>0){$context->diagnostics?->record('ack_handoff',['ok'=>true,'code'=>'ack_handoff_recovered','count'=>$recovery['recovered']]);}
        if(($recovery['rejected']??0)>0){$context->diagnostics?->record('ack_handoff',['ok'=>false,'code'=>'ack_handoff_rejected','count'=>$recovery['rejected']]);}
        if(!$guard()){return ['ok'=>false,'code'=>'worker_budget_exhausted'];}
        if(!$context->client->identity()->ok){return ['ok'=>false,'code'=>'operations_identity_unverified'];}
        $context->diagnostics?->context();$tick=$context->service->tick();$processed=0;$sessionResults=[];
        foreach($tick['sessions']??[]as$session){
            $safe=['ok'=>($session['ok']??false)===true,'code'=>SafeDiagnostics::code($session['code']??null)];
            $sessionResults[]=$safe;$context->diagnostics?->record('session',$safe+['pending'=>count($session['pending']??[])]);
            foreach($session['pending']??[]as$user=>$code){$context->diagnostics?->record('member_pending',['ok'=>false,'actor'=>$context->diagnostics->alias((string)$user),'code'=>$code]);}
        }
        $q=$context->db->pdo()->query("SELECT j.interaction_id FROM interaction_jobs j JOIN operations_ack_receipts a ON a.interaction_id=j.interaction_id WHERE j.scope_mode='operations' AND j.state IN ('pending','processing') ORDER BY j.created_at LIMIT 5");
        foreach($q->fetchAll(\PDO::FETCH_COLUMN)as$id){if(!$guard()){break;}if($context->worker->runOnce($id)!==null){$processed++;}}
        $weekly=[];
        if($context->weeklyRanking!==null&&$guard()){
            // Attendance, ACK recovery and closure have priority. Ranking never runs in the ACK callback.
            try{$result=$context->weeklyRanking->tick($guard);}
            catch(\Throwable $error){$context->diagnostics?->exception('weekly_ranking',$error);$result=['ok'=>false,'code'=>'weekly_ranking_unavailable'];}
            $context->diagnostics?->record('weekly_ranking',['ok'=>($result['ok']??false)===true,'code'=>$result['code']??'weekly_ranking_unavailable']);
            $weekly=['weekly_ranking'=>$result];
        }
        $context->diagnostics?->record('cycle',['ok'=>true,'code'=>'operations_cycle','count'=>$processed]);
        return ['ok'=>true,'code'=>'operations_cycle','sessions'=>array_column($sessionResults,'code'),'session_results'=>$sessionResults,'jobs_processed'=>$processed,
            'closure_policy'=>$context->closurePolicy,'atomic_member_updates'=>false,'residual_voice_race'=>$context->closurePolicy==='best_effort']+$weekly;
    }
    public function pollNeeded(OperationsContext $context):bool
    {
        if($context->service->scheduledWorkDueBy($this->clock->now()->getTimestamp()+55)){return true;}
        return $context->db->pdo()->query("SELECT 1 FROM interaction_jobs j JOIN operations_ack_receipts a ON a.interaction_id=j.interaction_id WHERE j.scope_mode='operations' AND j.state IN ('pending','processing') LIMIT 1")->fetchColumn()!==false;
    }
}
