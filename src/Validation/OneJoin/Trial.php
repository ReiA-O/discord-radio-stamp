<?php
declare(strict_types=1);
namespace RadioStamp\Validation\OneJoin;

use RadioStamp\Core\Clock;
use RadioStamp\Discord\Gateway;
use RadioStamp\Http\PrivateConfig;

/** One disposable owner trial. No runtime input chooses scope or storage. */
final class Trial
{
    public const NAME = 'attendance-one-join-20261019-v3';
    public const COMMAND_ID = '800000000000001009';
    public const NONCE = 'radio-onejoin-20261019-v3';
    public const MAX_SECONDS = Window::OVERALL_SECONDS;
    public const CONTENT = '【本人限定・別DBテスト】本番の出席・ロールには反映しません。\n入室済みの本人だけで確認します。接続したままお待ちください。こちらで「参加」→ 結果後に再度「参加」→「カード表示」を各1回確認します。終了後この投稿を削除します。';

    public static function validActivation(array $activation): bool
    {
        return Window::validActivation($activation);
    }

    public static function active(array $activation, Clock $clock): bool
    {
        $now = $clock->now()->getTimestamp();
        return self::validActivation($activation) && $now >= $activation['starts_at'] && $now < $activation['expires_at'];
    }

    public static function controls(string $session, string $month): array
    {
        return [
            'content' => str_replace('\\n', "\n", self::CONTENT), 'tts' => false, 'flags' => 4096,
            'allowed_mentions' => Gateway::ALLOWED_MENTIONS, 'nonce' => self::NONCE, 'enforce_nonce' => true,
            'components' => [['type' => 1, 'components' => [
                ['type' => 2, 'style' => 3, 'label' => '参加', 'custom_id' => 'radio:attend:' . $session],
                ['type' => 2, 'style' => 2, 'label' => 'カード表示', 'custom_id' => 'radio:card:' . $month],
            ]]],
        ];
    }

    public static function privateReply(string $content): \RadioStamp\Http\Response
    {
        return new \RadioStamp\Http\Response(200, ['type' => 4, 'data' => ['flags' => 64, 'content' => $content, 'allowed_mentions' => Gateway::ALLOWED_MENTIONS]]);
    }

    public static function safeCode(mixed $code): string
    {
        $allowed = ['trial_controls_ready', 'trial_cleanup_ready', 'not_in_voice', 'voice_unknown', 'voice_stale',
            'attended', 'already_attended', 'card_ready', 'session_closed', 'session_not_started', 'validation_bot_identity_unverified',
            'validation_followup_retry', 'worker_retry', 'controls_post_failed', 'cleanup_unconfirmed', 'unexpected_result',
            'response_finish_failed', 'runtime_failed', 'expired', 'identity_failed', 'followup_failed', 'card_preflight_failed'];
        if (is_string($code) && (in_array($code, $allowed, true) || preg_match('/\Abot_check_(self_bot|self_application|guild|bot_member|guild_roles|channel|identity|permissions_unknown|permissions)_failed\z/D', $code) === 1)) { return $code; }
        return 'unexpected_result';
    }

    public static function expected(string $step, string $code, array $counts, bool $image): bool
    {
        if ($counts['effects'] !== 0 || $counts['awards'] !== 0) { return false; }
        $zero = $counts['attendance'] === 0 && $counts['total'] === 0 && $counts['month'] === 0;
        $one = $counts['attendance'] === 1 && $counts['total'] === 1 && $counts['month'] === 1;
        return match ($step) {
            'check' => $code === 'trial_controls_ready' && $zero,
            'inside' => $code === 'attended' && $one,
            'duplicate' => $code === 'already_attended' && $one,
            'card' => $code === 'card_ready' && $one && $image,
            'cleanup' => $code === 'trial_cleanup_ready',
            default => false,
        };
    }

    public static function next(string $step): string
    {
        return match ($step) { 'check' => 'inside', 'inside' => 'duplicate', 'duplicate' => 'card', 'card' => 'complete', default => 'stopped' };
    }

    public static function fonts(): array
    {
        // Existing renderer allowlist only; no uploaded font or machine search.
        return ['/usr/share/fonts/ipa-gothic/ipag.ttf', '/usr/share/fonts/ipa-pgothic/ipagp.ttf',
            '/usr/share/fonts/ja/TrueType/kochi-gothic-subst.ttf', '/usr/share/fonts/vlgothic/VL-Gothic-Regular.ttf',
            '/usr/share/fonts/opentype/noto/NotoSansCJK-Regular.ttc', '/usr/share/fonts/ipaexg/ipaexg.ttf',
            'C:/Windows/Fonts/BIZ-UDGothicR.ttc', 'C:/Windows/Fonts/msgothic.ttc'];
    }
}
