<?php
declare(strict_types=1);
namespace RadioStamp\Discord;

/** Separate posting capability; weekly parts keep their own immutable key/payload. */
interface WeeklyRankingGateway extends Gateway
{
    public function sendWeeklyRankingResult(string $channel, array $payload, string $key): RestResult;
}
