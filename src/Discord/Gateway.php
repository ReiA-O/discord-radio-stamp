<?php
declare(strict_types=1);
namespace RadioStamp\Discord;

interface Gateway
{
    /** Ordinary messages never mention anyone. AchievementGateway is the separate one-user exception. */
    public const ALLOWED_MENTIONS = ['parse' => [], 'users' => [], 'roles' => [], 'replied_user' => false];
    public function voiceState(string $guildId, string $userId): VoiceState;
    public function roster(string $guildId, string $channelId): VoiceRoster;
    public function sendMessage(string $channelId, string $content, string $key, array $components = []): bool;
    public function setRoomOpen(string $channelId, bool $open, string $key): bool;
    public function moveMember(string $guildId, string $userId, string $destinationId, string $key): bool;
    public function disconnectMember(string $guildId, string $userId, string $key): bool;
    public function syncDecorationRole(string $guildId, string $userId, ?string $roleId, string $key): bool;
}
