<?php
declare(strict_types=1);
namespace RadioStamp\Discord;

use RadioStamp\Core\Clock;
use RadioStamp\Operations\SafeDiagnostics;
/** Small-group operational adapter; best-effort capability is deliberately separate from AtomicMemberGateway. */
final class ServerOperationsGateway implements DeliveryAwareGateway,RoleGateway,BestEffortMemberGateway,DayAchievementGateway
{
    public function __construct(private ServerOperationsClient $client,private Clock $clock,private array $config,private ?VoiceRosterSource $source=null,private ?SafeDiagnostics $diagnostics=null)
    {
        if($source instanceof GatewayRosterSession&&$diagnostics!==null){
            $source->setFailureReporter(static function(string $code)use($diagnostics):void{$diagnostics->record('roster',['known'=>false,'code'=>$code]);});
        }
    }
    private function allowed(string $guild,string $user):bool{return $guild===DiscordRestClient::GUILD_ID&&\RadioStamp\Operations\ParticipantScope::allows($this->config,$user);}
    public function voiceState(string $guild,string $user):VoiceState
    {
        $now=$this->clock->now()->getTimestamp();
        if(!$this->allowed($guild,$user)){return new VoiceState(false,null,$now);}
        $result=$this->client->readVoice($user);
        if(!$result->ok){return new VoiceState(false,null,$now);}
        $generation=0;
        if($this->source!==null){
            $this->source->pump();$projected=$this->source->voiceState($user);
            if($projected->known){if($projected->channelId!==$result->data['channel_id']){return new VoiceState(false,null,$now);}$generation=$projected->generation;}
        }
        return new VoiceState(true,$result->data['channel_id'],$now,$generation);
    }
    public function roster(string $guild,string $channel):VoiceRoster
    {
        $now=$this->clock->now()->getTimestamp();
        $scoped=$guild===DiscordRestClient::GUILD_ID&&$channel===DiscordRestClient::VOICE_CHANNEL_ID;
        if(!$scoped||$this->source===null||!$this->source->start()){$this->diagnostics?->record('roster',['known'=>false,'code'=>'roster_unknown']);return new VoiceRoster(false,[],$now);}
        $this->source->pump();$roster=$this->source->roster();
        if(!$roster->known||array_filter($roster->memberIds,fn(string $id):bool=>!$this->allowed($guild,$id))){$this->diagnostics?->record('roster',['known'=>false,'code'=>$roster->known?'roster_scope_rejected':'roster_unknown']);return new VoiceRoster(false,[],$now);}
        $this->diagnostics?->record('roster',['known'=>true,'code'=>'roster_known','count'=>count($roster->memberIds)]);
        return $roster;
    }
    public function sendMessage(string $channel,string $content,string $key,array $components=[]):bool{return $components===[]&&$this->client->postText($channel,$content,$key)->ok;}
    public function sendReceipt(string $sessionId,int $closesAt,string $key):bool{return $this->sendReceiptResult($sessionId,$closesAt,$key)->ok;}
    public function sendReceiptResult(string $sessionId,int $closesAt,string $key):RestResult
    {
        return $this->client->postReceipt($sessionId,$closesAt,$key,fn():bool=>$this->clock->now()->getTimestamp()<$closesAt);
    }
    public function setRoomOpen(string $channel,bool $open,string $key):bool{return $channel===DiscordRestClient::VOICE_CHANNEL_ID&&$this->client->setRoomConnect($open)->ok;}
    public function supportsRoleSync():bool{return $this->config['roles_enabled'];}
    public function syncDecorationRole(string $guild,string $user,?string $role,string $key):bool
    {
        if(!$this->allowed($guild,$user)||$role===null){return false;}
        $threshold=array_search($role,$this->config['role_ids'],true);
        return $threshold!==false&&$this->client->grantTitle($user,(int)$threshold)->ok;
    }
    public function sendAchievement(string $channel,string $user,int $threshold,int $total,string $key):bool
    {
        return $this->sendAchievementResult($channel,$user,$threshold,$total,$key)->ok;
    }
    public function sendAchievementResult(string $channel,string $user,int $threshold,int $total,string $key):RestResult
    {
        if($channel!==DiscordRestClient::RECORDS_CHANNEL_ID||!$this->allowed(DiscordRestClient::GUILD_ID,$user)||!$this->config['achievement_notifications_enabled']){
            return new RestResult(false,'scope_rejected',outcome:TransportOutcome::NotStarted);
        }
        return $this->client->postAchievement($user,$threshold,$total,$key);
    }
    public function sendDayAchievementResult(string $channel,string $user,int $milestone,int $totalDays,?string $ownerUserId,string $key):RestResult
    {
        if($channel!==DiscordRestClient::RECORDS_CHANNEL_ID||!$this->allowed(DiscordRestClient::GUILD_ID,$user)||!$this->config['achievement_notifications_enabled']){
            return new RestResult(false,'scope_rejected',outcome:TransportOutcome::NotStarted);
        }
        return $this->client->postDayAchievement($user,$milestone,$totalDays,$ownerUserId,$key);
    }
    public function supportsBestEffortMemberActions():bool{return $this->config['closure_policy']==='best_effort'&&$this->config['accept_voice_race_risk']&&$this->config['member_actions_enabled'];}
    public function moveMemberGuarded(string $guild,string $user,string $destination,MemberActionFence $fence,string $key):bool
    {
        return false; // Automatic relocation was removed; users move voluntarily after the announcement.
    }
    public function disconnectMemberGuarded(string $guild,string $user,MemberActionFence $fence,string $key):bool
    {
        if(!$this->supportsBestEffortMemberActions()||!$this->allowed($guild,$user)){$this->diagnostics?->record('member_guard',['ok'=>false,'actor'=>$this->diagnostics->alias($user),'code'=>'guard_disabled']);return false;}
        if($this->source?->matches($fence)!==true){$this->diagnostics?->record('member_guard',['ok'=>false,'actor'=>$this->diagnostics->alias($user),'code'=>'source_match_failed']);return false;}
        $result=$this->client->tryRelocate($user,null,$key,$fence);
        $this->diagnostics?->record('member_guard',['ok'=>$result->ok,'actor'=>$this->diagnostics->alias($user),'code'=>$result->code,'status'=>$result->status,'outcome'=>$result->outcome->value]);
        return $result->ok;
    }
    public function moveMember(string $guild,string $user,string $destination,string $key):bool{return false;}
    public function disconnectMember(string $guild,string $user,string $key):bool{return false;}
}
