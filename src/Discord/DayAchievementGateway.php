<?php
declare(strict_types=1);
namespace RadioStamp\Discord;

/** Separate day eligibility capability; it can never reinterpret a count-role threshold as days. */
interface DayAchievementGateway extends Gateway
{
    public function sendDayAchievementResult(string $channelId,string $userId,int $milestone,int $totalDays,?string $ownerUserId,string $key): RestResult;
}
