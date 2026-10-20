<?php
declare(strict_types=1);
namespace RadioStamp\Http;

/** Mock and explicitly enabled, fixed-owner validation are separate scopes. */
final class PrivateConfig
{
    public const APPLICATION_ID = '800000000000001003';
    public const GUILD_ID = '800000000000001001';
    public const OWNER_ID = '800000000000001021';
    public const GUIDE_ID = '800000000000001005';
    public const RECORDS_ID = '800000000000001006';
    public const VOICE_ID = '800000000000001007';

    public static function ready(array $config): bool
    {
        return self::signatureReady($config) &&
            is_string($config['token_encryption_key_hex'] ?? null) && preg_match('/\A[0-9a-fA-F]{64}\z/D', $config['token_encryption_key_hex']) === 1 &&
            is_string($config['private_db_path'] ?? null) && $config['private_db_path'] !== '' &&
            (($config['mode'] ?? null) === 'mock' || self::botConfigured($config));
    }

    public static function signatureReady(array $config): bool
    {
        $admins = $config['admin_user_ids'] ?? [];
        return in_array($config['mode'] ?? null, ['mock', 'admin_validation'], true) &&
            ($config['admin_validation_enabled'] ?? false) === true &&
            self::snowflake($config['application_id'] ?? null) && self::snowflake($config['guild_id'] ?? null) &&
            is_array($admins) && count($admins) > 0 && count(array_filter($admins, self::snowflake(...))) === count($admins) &&
            is_string($config['public_key_hex'] ?? null) && preg_match('/\A[0-9a-fA-F]{64}\z/D', $config['public_key_hex']) === 1 &&
            (($config['mode'] ?? null) === 'mock' || self::validationScope($config));
    }

    public static function validationScope(array $config): bool
    {
        return ($config['mode'] ?? null) === 'admin_validation' &&
            ($config['participant_commands_enabled'] ?? false) === false &&
            ($config['achievement_notifications_enabled'] ?? false) === false &&
            ($config['application_id'] ?? null) === self::APPLICATION_ID && ($config['guild_id'] ?? null) === self::GUILD_ID &&
            ($config['admin_user_ids'] ?? null) === [self::OWNER_ID] &&
            ($config['text_channel_id'] ?? null) === self::GUIDE_ID && ($config['records_channel_id'] ?? null) === self::RECORDS_ID &&
            ($config['voice_channel_id'] ?? null) === self::VOICE_ID &&
            ($config['timezone'] ?? null) === 'Asia/Tokyo' &&
            ($config['member_actions_enabled'] ?? null) === false && ($config['live_automation_enabled'] ?? null) === false &&
            ($config['role_ids'] ?? null) === [];
    }

    /** Explicit local simulation only; cannot broaden the fixed-owner REST scope. */
    public static function participantMockScope(array $config): bool
    {
        return ($config['mode'] ?? null) === 'mock' && ($config['participant_commands_enabled'] ?? false) === true;
    }

    public static function botConfigured(array $config): bool
    {
        return is_string($config['bot_token'] ?? null) && preg_match('/\A[A-Za-z0-9._-]{20,256}\z/D', $config['bot_token']) === 1;
    }

    public static function snowflake(mixed $value): bool
    {
        return is_string($value) && preg_match('/\A[1-9][0-9]{16,19}\z/D', $value) === 1;
    }

    public static function requirePrivatePath(string $path, string $publicRoot): void
    {
        if (is_link($path)) {
            throw new \InvalidArgumentException('Private storage must not be a symbolic link.');
        }
        $parent = realpath(dirname($path));
        $public = realpath($publicRoot);
        if ($parent === false || $public === false || !self::absolute($path)) {
            throw new \InvalidArgumentException('A private absolute storage location is required.');
        }
        $candidate = is_file($path) ? realpath($path) : $parent . DIRECTORY_SEPARATOR . basename($path);
        $normalize = static fn(string $p): string => strtolower(str_replace('\\', '/', $p));
        $candidate = $normalize((string) $candidate);
        $public = rtrim($normalize($public), '/');
        if ($candidate === $public || str_starts_with($candidate, $public . '/')) {
            throw new \InvalidArgumentException('Storage must be outside the public document root.');
        }
    }

    public static function load(string $path, string $publicRoot, bool $requireQueueReady = true,?callable $scopeValidator=null): array
    {
        try { self::requirePrivatePath($path, $publicRoot); }
        catch (\Throwable) { throw new \RuntimeException('Private configuration is unavailable.', 1101); }
        if (!is_file($path)) { throw new \RuntimeException('Private configuration is unavailable.', 1102); }
        $bufferLevel = ob_get_level();
        if (!ob_start(static fn(string $discarded): string => '')) { throw new \RuntimeException('Private configuration is unavailable.', 1103); }
        // The existing require is the sole configuration read. Neither its output
        // nor warnings, exception details, or a previous exception are propagated.
        set_error_handler(static fn(): bool => true);
        try {
            try { $config = require $path; }
            catch (\ParseError) { throw new \RuntimeException('Private configuration is unavailable.', 1104); }
            catch (\Throwable) { throw new \RuntimeException('Private configuration is unavailable.', 1105); }
            $output = ob_get_contents();
            $bufferChanged = ob_get_level() !== $bufferLevel + 1;
            $bomOnly = !$bufferChanged && $output === "\xEF\xBB\xBF";
            $unexpectedOutput = $bufferChanged || $output !== '';
            unset($output);
        } finally {
            while (ob_get_level() > $bufferLevel) { ob_end_clean(); }
            restore_error_handler();
        }
        if ($unexpectedOutput) { throw new \RuntimeException('Private configuration is unavailable.', $bomOnly ? 1107 : 1106); }
        if (!is_array($config)) { throw new \RuntimeException('Private configuration is unavailable.', 1108); }
        if (!($scopeValidator===null?self::signatureReady($config):$scopeValidator($config)===true)) { throw new \RuntimeException('Private configuration is unavailable.', 1109); }
        if ($requireQueueReady && !self::ready($config)) { throw new \RuntimeException('Private configuration is unavailable.', 1110); }
        if (!is_string($config['private_db_path'] ?? null)) { throw new \RuntimeException('Private configuration is unavailable.', 1111); }
        try { self::requirePrivatePath($config['private_db_path'], $publicRoot); }
        catch (\Throwable) { throw new \RuntimeException('Private configuration is unavailable.', 1112); }
        return $config;
    }

    private static function absolute(string $path): bool
    {
        return str_starts_with($path, '/') || preg_match('/\A[A-Za-z]:[\\\\\/]/', $path) === 1;
    }
}
