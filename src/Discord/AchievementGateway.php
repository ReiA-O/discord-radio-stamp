<?php
declare(strict_types=1);
namespace RadioStamp\Discord;

/** Separate capability: only a role-confirmed achievement may notify one specific user. */
interface AchievementGateway extends Gateway
{
    public function sendAchievement(string $channelId, string $userId, int $threshold, int $totalCount, string $key): bool;
}
