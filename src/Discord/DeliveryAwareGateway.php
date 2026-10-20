<?php
declare(strict_types=1);
namespace RadioStamp\Discord;

/** Optional richer capability; legacy bool failures continue to mean uncertain delivery. */
interface DeliveryAwareGateway extends ReceiptGateway,AchievementGateway
{
    public function sendReceiptResult(string $sessionId,int $closesAt,string $key):RestResult;
    public function sendAchievementResult(string $channelId,string $userId,int $threshold,int $totalCount,string $key):RestResult;
}
