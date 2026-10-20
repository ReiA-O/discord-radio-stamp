<?php
declare(strict_types=1);
namespace RadioStamp\Discord;

use RadioStamp\Core\Clock;

/** Deliberately cannot enumerate occupants or mutate Discord members, roles, or rooms. */
final class RestGateway implements AdminValidationGateway
{
    public const GUILD_ID = DiscordRestClient::GUILD_ID;
    public const ADMIN_USER_ID = DiscordRestClient::ADMIN_USER_ID;
    public const VOICE_CHANNEL_ID = DiscordRestClient::VOICE_CHANNEL_ID;
    /** The optional observer is code-only and receives a credential-free DTO. */
    public function __construct(private readonly DiscordRestClient $client, private readonly Clock $clock, private readonly ?\Closure $presenceObserver = null) {}
    public function permitsValidationUser(string $guildId, string $userId): bool { return $guildId === self::GUILD_ID && $userId === self::ADMIN_USER_ID; }
    public function voiceState(string $guildId, string $userId): VoiceState
    {
        $now = $this->clock->now()->getTimestamp();
        if (!$this->permitsValidationUser($guildId, $userId)) { return new VoiceState(false, null, $now); }
        $result = $this->client->adminVoice();
        if ($this->presenceObserver !== null) {
            try { ($this->presenceObserver)(PresenceDiagnostic::fromResult($result)); }
            catch (\Throwable) { return new VoiceState(false, null, $now); }
        }
        // Even Unknown Voice State/404 remains unknown until its absence semantics are verified.
        return new VoiceState($result->ok, $result->ok ? $result->data['channel_id'] : null, $now);
    }
    public function roster(string $guildId, string $channelId): VoiceRoster { return new VoiceRoster(false, [], $this->clock->now()->getTimestamp()); }
    public function sendMessage(string $channelId, string $content, string $key, array $components = []): bool { return false; }
    public function setRoomOpen(string $channelId, bool $open, string $key): bool { return false; }
    public function moveMember(string $guildId, string $userId, string $destinationId, string $key): bool { return false; }
    public function disconnectMember(string $guildId, string $userId, string $key): bool { return false; }
    public function syncDecorationRole(string $guildId, string $userId, ?string $roleId, string $key): bool { return false; }
}
