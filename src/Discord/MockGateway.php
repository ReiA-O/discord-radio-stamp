<?php
declare(strict_types=1);
namespace RadioStamp\Discord;

use RadioStamp\Core\Clock;
use RadioStamp\Core\SystemClock;

/** In-memory simulation only. There is deliberately no HTTP client or live adapter. */
final class MockGateway implements AtomicMemberGateway, AchievementGateway, DayAchievementGateway, WeeklyRankingGateway
{
    /** @var array<string,VoiceState> Keyed by user ID. */
    public array $voiceStates = [];
    /** @var array<string,VoiceRoster> Keyed by channel ID. */
    public array $rosters = [];
    /** @var array<string,bool|list<bool>> Method defaults or per-key overrides. */
    public array $results = [];
    /** Explicit local transport facts for day-notice fault injection; bool failures remain uncertain. */
    public array $dayAchievementOutcomes = [];
    public array $weeklyRankingOutcomes = [];
    public array $actionLog = [];
    public array $roomStates = [];
    public array $decorationRoles = [];
    public array $safetyLog = [];
    public bool $conditionalMemberActionsSupported = true;
    /** Tests may inject an event immediately before the atomic comparison; never a live callback. */
    public ?\Closure $beforeConditionalAction = null;
    public ?GatewayVoiceTracker $voiceTracker;
    private array $successfulKeys = [];
    private Clock $clock;
    private int $voiceGeneration = 0;

    public function __construct(?Clock $clock = null, ?GatewayVoiceTracker $voiceTracker = null)
    {
        $this->clock = $clock ?? new SystemClock(); $this->voiceTracker = $voiceTracker;
    }

    public function setVoiceState(string $userId, ?string $channelId, bool $known = true, ?int $observedAt = null): void
    {
        $this->voiceStates[$userId] = new VoiceState($known, $channelId, $observedAt ?? $this->now(), ++$this->voiceGeneration);
    }

    public function setRoster(string $channelId, array $memberIds, bool $known = true, ?int $observedAt = null): void
    {
        $this->rosters[$channelId] = new VoiceRoster($known, array_values(array_unique(array_map('strval', $memberIds))), $observedAt ?? $this->now(), ++$this->voiceGeneration);
    }

    /** A sequence such as [false,true] models a transient failure, then success. */
    public function setResult(string $methodOrKey, bool|array $result): void { $this->results[$methodOrKey] = $result; }

    public function voiceState(string $guildId, string $userId): VoiceState
    {
        return $this->voiceTracker?->voiceState($guildId, $userId) ?? ($this->voiceStates[$userId] ?? new VoiceState(false, null, $this->now(), $this->voiceGeneration));
    }

    public function roster(string $guildId, string $channelId): VoiceRoster
    {
        return $this->voiceTracker?->roster($guildId, $channelId) ?? ($this->rosters[$channelId] ?? new VoiceRoster(false, [], $this->now(), $this->voiceGeneration));
    }

    public function supportsConditionalMemberActions(): bool { return $this->conditionalMemberActionsSupported; }

    public function moveMemberIfUnchanged(string $guildId, string $userId, string $destinationId, MemberActionFence $fence, string $key): bool
    {
        if (isset($this->successfulKeys[$key])) { return true; }
        if (!$this->checkFence($guildId, $userId, $fence, 'move')) { return false; }
        // No await, socket, REST GET/PATCH pair, or intervening callback exists between
        // this in-memory comparison and mutation. This models a capability Discord lacks.
        return $this->moveMember($guildId, $userId, $destinationId, $key);
    }

    public function disconnectMemberIfUnchanged(string $guildId, string $userId, MemberActionFence $fence, string $key): bool
    {
        if (isset($this->successfulKeys[$key])) { return true; }
        if (!$this->checkFence($guildId, $userId, $fence, 'disconnect')) { return false; }
        return $this->disconnectMember($guildId, $userId, $key);
    }

    private function checkFence(string $guildId, string $userId, MemberActionFence $fence, string $operation): bool
    {
        if (!$this->conditionalMemberActionsSupported) { return $this->rejectFence('conditional_transport_unsupported', $userId); }
        if ($this->beforeConditionalAction !== null) { ($this->beforeConditionalAction)($this, $fence, $operation); }
        if (!$this->conditionalMemberActionsSupported) { return $this->rejectFence('conditional_transport_unsupported', $userId); }
        $state = $this->voiceState($guildId, $userId);
        $age = $this->now() - $state->observedAt; $fenceAge = $this->now() - $fence->observedAt;
        if ($fence->guildId !== $guildId || $fence->userId !== $userId || !$state->known || $age < 0 || $age > 5 || $fenceAge < 0 || $fenceAge > 5) {
            return $this->rejectFence('unknown_stale_or_mismatched_fence', $userId);
        }
        if ($state->channelId !== $fence->sourceChannelId || $state->generation !== $fence->generation) {
            return $this->rejectFence('voice_changed', $userId);
        }
        return true;
    }

    private function rejectFence(string $code, string $userId): bool
    {
        $this->safetyLog[] = ['code' => $code, 'user_id' => $userId, 'at' => $this->now()]; return false;
    }

    public function sendMessage(string $channelId, string $content, string $key, array $components = []): bool
    {
        return $this->action('sendMessage', $key, ['channel_id' => $channelId, 'content' => $content, 'components' => $components, 'allowed_mentions' => self::ALLOWED_MENTIONS, 'tts' => false]);
    }

    public function sendAchievement(string $channelId, string $userId, int $threshold, int $totalCount, string $key): bool
    {
        $payload = AchievementMessage::build($userId, $threshold, $totalCount);
        return $this->action('sendAchievement', $key, ['channel_id' => $channelId, 'user_id' => $userId,
            'threshold' => $threshold, 'total_count' => $totalCount] + $payload);
    }

    public function sendDayAchievementResult(string $channelId,string $userId,int $milestone,int $totalDays,?string $ownerUserId,string $key):RestResult
    {
        $payload=AchievementMessage::buildDay($userId,$milestone,$totalDays,$ownerUserId);
        $outcome=array_shift($this->dayAchievementOutcomes);
        if($outcome instanceof TransportOutcome){$this->results[$key]=$outcome===TransportOutcome::Succeeded;}
        $ok=$this->action('sendDayAchievement',$key,['channel_id'=>$channelId,'user_id'=>$userId,'milestone'=>$milestone,'total_days'=>$totalDays]+$payload);
        $outcome=$outcome instanceof TransportOutcome?$outcome:($ok?TransportOutcome::Succeeded:TransportOutcome::Uncertain);
        return new RestResult($ok,$ok?'message_confirmed':'delivery_'.$outcome->value,outcome:$outcome);
    }

    public function sendWeeklyRankingResult(string $channel,array $payload,string $key):RestResult
    {
        if(!\RadioStamp\Core\WeeklyRanking::validPayload($payload)){return new RestResult(false,'scope_rejected',outcome:TransportOutcome::NotStarted);}
        $outcome=array_shift($this->weeklyRankingOutcomes);
        if($outcome instanceof TransportOutcome){$this->results[$key]=$outcome===TransportOutcome::Succeeded;}
        $ok=$this->action('sendWeeklyRanking',$key,['channel_id'=>$channel]+$payload);
        $outcome=$outcome instanceof TransportOutcome?$outcome:($ok?TransportOutcome::Succeeded:TransportOutcome::Uncertain);
        return new RestResult($ok,$ok?'message_confirmed':'delivery_'.$outcome->value,
            data:$ok?['id'=>'900000000000009800','channel_id'=>$channel]:[],outcome:$outcome);
    }

    public function setRoomOpen(string $channelId, bool $open, string $key): bool
    {
        $replay = isset($this->successfulKeys[$key]);
        $ok = $this->action('setRoomOpen', $key, ['channel_id' => $channelId, 'open' => $open]);
        if ($ok && !$replay) { $this->roomStates[$channelId] = $open; }
        return $ok;
    }

    public function moveMember(string $guildId, string $userId, string $destinationId, string $key): bool
    {
        $replay = isset($this->successfulKeys[$key]);
        $ok = $this->action('moveMember', $key, ['guild_id' => $guildId, 'user_id' => $userId, 'destination_id' => $destinationId]);
        if ($ok && !$replay) { $this->applyVoiceChange($userId, $destinationId); }
        return $ok;
    }

    public function disconnectMember(string $guildId, string $userId, string $key): bool
    {
        $replay = isset($this->successfulKeys[$key]);
        $ok = $this->action('disconnectMember', $key, ['guild_id' => $guildId, 'user_id' => $userId]);
        if ($ok && !$replay) { $this->applyVoiceChange($userId, null); }
        return $ok;
    }

    public function syncDecorationRole(string $guildId, string $userId, ?string $roleId, string $key): bool
    {
        $replay = isset($this->successfulKeys[$key]);
        $ok = $this->action('syncDecorationRole', $key, ['guild_id' => $guildId, 'user_id' => $userId, 'role_id' => $roleId]);
        if ($ok && !$replay) { $this->decorationRoles[$userId] = $roleId; }
        return $ok;
    }

    private function action(string $method, string $key, array $data): bool
    {
        if (isset($this->successfulKeys[$key])) { return true; }
        $setting = array_key_exists($key, $this->results) ? $key : $method;
        $value = $this->results[$setting] ?? true;
        if (is_array($value)) {
            $ok = (bool)($value[0] ?? true);
            if (count($value) > 1) { array_shift($this->results[$setting]); }
        } else { $ok = $value; }
        $this->actionLog[] = ['method' => $method, 'key' => $key, 'at' => $this->now(), 'ok' => $ok] + $data;
        if ($ok) { $this->successfulKeys[$key] = true; }
        return $ok;
    }

    private function applyVoiceChange(string $userId, ?string $destinationId): void
    {
        // An attached event projection is deliberately not rewritten on an action ACK.
        // Its roster changes only after an ordered VOICE_STATE_UPDATE is received;
        // the core must keep closure pending while it still observes this member.
        $this->setVoiceState($userId, $destinationId);
        foreach ($this->rosters as $channel => $roster) {
            // Preserve an explicitly unknown roster; an action cannot establish completeness.
            $members = array_values(array_filter($roster->memberIds, static fn($id) => (string)$id !== $userId));
            if ($channel === $destinationId && $roster->known) { $members[] = $userId; }
            $this->rosters[$channel] = new VoiceRoster($roster->known, array_values(array_unique($members)), $roster->observedAt, ++$this->voiceGeneration);
        }
    }

    private function now(): int { return $this->clock->now()->getTimestamp(); }
}
