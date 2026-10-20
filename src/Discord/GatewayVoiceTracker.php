<?php
declare(strict_types=1);
namespace RadioStamp\Discord;

use RadioStamp\Core\Clock;

/**
 * Offline event reducer; it opens no socket and stores no raw frame, session ID,
 * resume URL, token, or credentials. A future receiver must deliver every dispatch,
 * including unrelated events, in order and invalidate on disconnect/reconnect.
 * READY and RESUMED alone never establish a complete guild voice baseline.
 */
final class GatewayVoiceTracker
{
    private bool $connected = false;
    private ?int $lastSequence = null;
    private ?int $lastHealthAt = null;
    private int $generation = 0;
    /** @var array<string,array{complete:bool,channels:array<string,string>,reason:string}> */
    private array $guilds = [];

    public function __construct(private Clock $clock, private int $healthTimeoutSeconds = 45)
    {
        if ($healthTimeoutSeconds < 1 || $healthTimeoutSeconds > 120) { throw new \InvalidArgumentException('Invalid local transport-health timeout.'); }
    }

    public function onDispatch(string $event, int $sequence, array $data): array
    {
        if ($sequence < 0) { $this->invalidateAll('invalid_sequence'); return $this->result(false, 'invalid_sequence'); }
        if ($event === 'READY' || $event === 'RESUMED') {
            $this->invalidateAll($event === 'READY' ? 'ready_requires_snapshot' : 'resumed_requires_snapshot');
            $this->connected = true;
            $this->lastSequence = $sequence;
            $this->lastHealthAt = $this->now();
            return $this->result(true, $event === 'READY' ? 'ready_requires_snapshot' : 'resumed_requires_snapshot');
        }
        $this->invalidateStaleHealth();
        if (!$this->connected || $this->lastSequence === null) { return $this->result(false, 'stream_not_ready'); }
        if ($sequence !== $this->lastSequence + 1) {
            $reason = $sequence <= $this->lastSequence ? 'sequence_replay_or_reverse' : 'sequence_gap';
            $this->invalidateAll($reason);
            // Drop the untrusted dispatch. A later contiguous GUILD_CREATE is needed.
            $this->lastSequence = max($this->lastSequence, $sequence);
            $this->lastHealthAt = $this->now();
            return $this->result(false, $reason);
        }
        $this->lastSequence = $sequence;
        $this->lastHealthAt = $this->now();
        if ($event === 'GUILD_CREATE') { return $this->applyGuildCreate($data); }
        if ($event === 'GUILD_DELETE') {
            $guildId = $this->identifier($data['id'] ?? null);
            if ($guildId === null) { $this->invalidateAll('invalid_guild_event'); return $this->result(false, 'invalid_guild_event'); }
            $this->invalidateGuild($guildId, 'guild_unavailable');
            return $this->result(true, 'guild_unavailable');
        }
        if ($event === 'VOICE_STATE_UPDATE') { return $this->applyVoiceUpdate($data); }
        // Unrelated events still consume the global dispatch sequence.
        return $this->result(true, 'dispatch_consumed');
    }

    public function onDisconnect(): void
    {
        $this->invalidateAll('disconnected'); $this->connected = false; $this->lastSequence = null; $this->lastHealthAt = null;
    }

    public function onReconnect(): void
    {
        $this->invalidateAll('reconnected_requires_snapshot'); $this->connected = false; $this->lastSequence = null; $this->lastHealthAt = null;
    }

    /** A health observation only: ACK cannot restore a missing baseline or prove atomicity. */
    public function heartbeatAcknowledged(): bool
    {
        if (!$this->connected || $this->lastSequence === null) { return false; }
        $this->invalidateStaleHealth();
        $this->lastHealthAt = $this->now();
        return true;
    }

    public function snapshot(string $guildId): VoiceSnapshot
    {
        $this->invalidateStaleHealth();
        $guild = $this->guilds[$guildId] ?? ['complete' => false, 'channels' => [], 'reason' => 'no_complete_snapshot'];
        $age = $this->lastHealthAt === null ? null : $this->now() - $this->lastHealthAt;
        $healthy = $this->connected && $age !== null && $age >= 0 && $age < $this->healthTimeoutSeconds;
        $known = $guild['complete'] && $healthy;
        $reason = !$healthy ? 'transport_unknown_or_stale' : ($known ? 'ordered_local_projection' : $guild['reason']);
        // observedAt is when the local projection is read, not the last voice event's age.
        // A quiet VC can remain usable under healthy heartbeats; this is not a server CAS.
        return new VoiceSnapshot($known, $guildId, $known ? $guild['channels'] : [], $this->now(), $this->generation, $this->lastSequence, $this->lastHealthAt, $reason);
    }

    public function voiceState(string $guildId, string $userId): VoiceState { return $this->snapshot($guildId)->voiceState($userId); }
    public function roster(string $guildId, string $channelId): VoiceRoster { return $this->snapshot($guildId)->roster($channelId); }

    private function applyGuildCreate(array $data): array
    {
        $guildId = $this->identifier($data['id'] ?? null);
        if ($guildId === null) { $this->invalidateAll('invalid_guild_snapshot'); return $this->result(false, 'invalid_guild_snapshot'); }
        if (($data['unavailable'] ?? false) === true || !array_key_exists('voice_states', $data) || !is_array($data['voice_states']) || !array_is_list($data['voice_states'])) {
            $this->invalidateGuild($guildId, 'incomplete_guild_snapshot');
            return $this->result(false, 'incomplete_guild_snapshot');
        }
        $channels = []; $seen = [];
        foreach ($data['voice_states'] as $state) {
            if (!is_array($state) || !array_key_exists('channel_id', $state)) { $this->invalidateGuild($guildId, 'invalid_voice_snapshot'); return $this->result(false, 'invalid_voice_snapshot'); }
            $userId = $this->identifier($state['user_id'] ?? null);
            $channelId = $state['channel_id'] === null ? null : $this->identifier($state['channel_id']);
            if ($userId === null || ($state['channel_id'] !== null && $channelId === null) || isset($seen[$userId])) {
                $this->invalidateGuild($guildId, 'invalid_voice_snapshot'); return $this->result(false, 'invalid_voice_snapshot');
            }
            $seen[$userId] = true;
            if ($channelId !== null) { $channels[$userId] = $channelId; }
        }
        $this->guilds[$guildId] = ['complete' => true, 'channels' => $channels, 'reason' => 'ordered_local_projection'];
        $this->generation++;
        return $this->result(true, 'complete_guild_snapshot');
    }

    private function applyVoiceUpdate(array $data): array
    {
        // DM voice events do not establish or invalidate a guild baseline.
        if (!array_key_exists('guild_id', $data)) { return $this->result(true, 'non_guild_voice_ignored'); }
        $guildId = $this->identifier($data['guild_id']);
        if ($guildId === null) { $this->invalidateAll('invalid_voice_event'); return $this->result(false, 'invalid_voice_event'); }
        $userId = $this->identifier($data['user_id'] ?? null);
        $hasChannel = array_key_exists('channel_id', $data);
        $channelId = $hasChannel && $data['channel_id'] !== null ? $this->identifier($data['channel_id']) : null;
        if ($userId === null || !$hasChannel || ($data['channel_id'] !== null && $channelId === null)) {
            $this->invalidateGuild($guildId, 'invalid_voice_event'); return $this->result(false, 'invalid_voice_event');
        }
        if (!($this->guilds[$guildId]['complete'] ?? false)) { return $this->result(false, 'complete_snapshot_required'); }
        if ($channelId === null) { unset($this->guilds[$guildId]['channels'][$userId]); }
        else { $this->guilds[$guildId]['channels'][$userId] = $channelId; }
        $this->generation++;
        return $this->result(true, 'voice_update_applied');
    }

    private function invalidateGuild(string $guildId, string $reason): void
    {
        $this->guilds[$guildId] = ['complete' => false, 'channels' => [], 'reason' => $reason]; $this->generation++;
    }

    private function invalidateAll(string $reason): void
    {
        foreach (array_keys($this->guilds) as $guildId) { $this->guilds[$guildId] = ['complete' => false, 'channels' => [], 'reason' => $reason]; }
        $this->generation++;
    }

    private function invalidateStaleHealth(): void
    {
        if (!$this->connected || $this->lastHealthAt === null) { return; }
        $age = $this->now() - $this->lastHealthAt;
        if ($age >= 0 && $age < $this->healthTimeoutSeconds) { return; }
        foreach ($this->guilds as $guild) {
            if ($guild['complete']) { $this->invalidateAll('transport_health_lost_requires_snapshot'); break; }
        }
    }

    private function identifier(mixed $value): ?string
    {
        return is_string($value) && preg_match('/^[A-Za-z0-9_-]{1,64}$/D', $value) ? $value : null;
    }
    private function now(): int { return $this->clock->now()->getTimestamp(); }
    private function result(bool $ok, string $code): array { return ['ok' => $ok, 'code' => $code, 'generation' => $this->generation]; }
}
