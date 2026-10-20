<?php
declare(strict_types=1);
namespace RadioStamp\Core;

use PDO;

/** Eligibility only: no reward type, fulfilment, streak, role, or public effect. */
final class ParticipationDays
{
    public const MILESTONES = [10, 30, 60, 100, 300, 365];

    public static function reached(int $days): array
    {
        return array_values(array_filter(self::MILESTONES, static fn(int $milestone): bool => $milestone <= $days));
    }

    /** Use the actual attendance instant in JST, independently of the scheduled session date. */
    public static function summary(PDO $pdo, string $userId, string $month): array
    {
        $query = $pdo->prepare("SELECT DISTINCT date(attended_at, 'unixepoch', '+9 hours') AS day FROM attendance WHERE user_id=? ORDER BY day");
        $query->execute([$userId]);
        $dates = $query->fetchAll(PDO::FETCH_COLUMN);
        $monthDays = count(array_filter($dates, static fn(string $date): bool => substr($date, 0, 7) === $month));
        $totalDays = count($dates);
        return ['total_days' => $totalDays, 'month_days' => $monthDays, 'day_milestones' => self::reached($totalDays)];
    }
}
