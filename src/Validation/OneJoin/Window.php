<?php
declare(strict_types=1);
namespace RadioStamp\Validation\OneJoin;

/** Fixed operational timestamps only; no network, identity or credential data. */
final class Window
{
    public const OVERALL_SECONDS = 3600;
    public const OWNER_SECONDS = 900;
    public const RELAY_SECONDS = 300;
    public const VERIFY_SECONDS = 300;
    public const CLEANUP_SECONDS = 600;
    public const INVITE_RESERVE = self::OWNER_SECONDS + self::RELAY_SECONDS + self::VERIFY_SECONDS + self::CLEANUP_SECONDS;

    public static function plan(int $preparationStartedAt, int $invitationSentAt): ?array
    {
        if ($preparationStartedAt <= 0 || $preparationStartedAt > PHP_INT_MAX - self::OVERALL_SECONDS ||
            $invitationSentAt < $preparationStartedAt) { return null; }
        $deadline = $preparationStartedAt + self::OVERALL_SECONDS;
        if ($invitationSentAt > $deadline - self::INVITE_RESERVE) { return null; }
        return [
            'overall_deadline_at' => $deadline,
            'latest_invitation_at' => $deadline - self::INVITE_RESERVE,
            'owner_reply_deadline_at' => $invitationSentAt + self::OWNER_SECONDS,
            'relay_deadline_at' => $invitationSentAt + self::OWNER_SECONDS + self::RELAY_SECONDS,
            'verification_deadline_at' => $deadline - self::CLEANUP_SECONDS,
        ];
    }

    public static function activation(int $preparationStartedAt, int $invitationSentAt, int $ownerJoinReplyAt, int $startsAt): array
    {
        $plan = self::plan($preparationStartedAt, $invitationSentAt);
        $activation = [
            'enabled' => true, 'preparation_started_at' => $preparationStartedAt,
            'invitation_sent_at' => $invitationSentAt, 'owner_join_reply_at' => $ownerJoinReplyAt,
            'starts_at' => $startsAt, 'expires_at' => $plan['verification_deadline_at'] ?? 0,
        ];
        if (!self::validActivation($activation)) { throw new \InvalidArgumentException('trial_window_invalid'); }
        return $activation;
    }

    public static function validActivation(array $activation): bool
    {
        if (array_keys($activation) !== ['enabled', 'preparation_started_at', 'invitation_sent_at', 'owner_join_reply_at', 'starts_at', 'expires_at'] ||
            $activation['enabled'] !== true) { return false; }
        foreach (array_slice($activation, 1) as $value) { if (!is_int($value) || $value <= 0) { return false; } }
        $plan = self::plan($activation['preparation_started_at'], $activation['invitation_sent_at']);
        if ($plan === null) { return false; }
        return $activation['owner_join_reply_at'] >= $activation['invitation_sent_at'] &&
            $activation['owner_join_reply_at'] <= $plan['owner_reply_deadline_at'] &&
            $activation['starts_at'] >= $activation['owner_join_reply_at'] &&
            $activation['starts_at'] <= $plan['relay_deadline_at'] &&
            $activation['starts_at'] <= $plan['verification_deadline_at'] - self::VERIFY_SECONDS &&
            $activation['expires_at'] === $plan['verification_deadline_at'];
    }

    public static function overallDeadline(array $activation): int
    {
        return $activation['preparation_started_at'] + self::OVERALL_SECONDS;
    }
}
