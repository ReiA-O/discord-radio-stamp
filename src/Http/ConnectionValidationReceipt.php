<?php
declare(strict_types=1);
namespace RadioStamp\Http;

use RadioStamp\Discord\CurlHttpAdapter;
use RadioStamp\Discord\DiscordRestClient;
use RadioStamp\Discord\HttpAdapter;
use RadioStamp\Discord\HttpRequest;
use RadioStamp\Discord\HttpResponse;

/** A one-attempt, fixed-read check; its files contain no Discord response data. */
final class ConnectionValidationReceipt
{
    private const CHECKS = ['bot_identity', 'application_identity', 'guild_membership',
        'guide_permissions', 'records_permissions', 'voice_permissions', 'restricted_permissions'];
    private const RESULT_CODES = ['bot_check_passed', 'bot_check_self_bot_failed',
        'bot_check_self_application_failed', 'bot_check_guild_failed', 'bot_check_bot_member_failed',
        'bot_check_guild_roles_failed', 'bot_check_identity_failed', 'bot_check_permissions_unknown',
        'bot_check_channel_failed', 'bot_check_permissions_failed'];

    public function __construct(private readonly ?HttpAdapter $http = null) {}
    public function __debugInfo(): array { return ['scope' => 'fixed_read_only_validation']; }

    public static function enabled(#[\SensitiveParameter] array $config): bool
    {
        return ($config['runtime_check_enabled'] ?? null) === true &&
            ($config['command_registration_enabled'] ?? null) === false &&
            PrivateConfig::validationScope($config) && PrivateConfig::ready($config);
    }

    public function run(#[\SensitiveParameter] array $config, string $privateRoot, string $publicRoot): array
    {
        if (!self::enabled($config)) { return self::result(false, 'connection_validation_disabled'); }
        $temporary = null;
        $temporaryCreated = false;
        $temporaryIdentity = null;
        $previousMask = umask(0077);
        $bufferLevel = ob_get_level();
        set_error_handler(static fn(): bool => true);
        try {
            if (!ob_start(static fn(string $discarded): string => '')) {
                return self::result(false, 'connection_validation_unavailable');
            }
            [$root, $directory] = self::storage($privateRoot, $publicRoot);
            $identity = self::directoryIdentity($directory);
            $marker = $directory . DIRECTORY_SEPARATOR . 'connection-validation-20261017.attempt';
            $destination = $directory . DIRECTORY_SEPARATOR . 'connection-validation-20261017.json';
            foreach ([$marker, $destination] as $path) { PrivateConfig::requirePrivatePath($path, $publicRoot); }
            if (file_exists($marker) || file_exists($destination)) {
                return self::result(false, 'connection_validation_already_attempted');
            }
            // Exclusive creation precedes every HTTP request, including failed checks.
            // A failed attempt deliberately keeps this marker and cannot retry itself.
            $handle = fopen($marker, 'x+b');
            if ($handle === false) {
                return self::result(false, file_exists($marker) ? 'connection_validation_already_attempted' : 'connection_validation_unavailable');
            }
            try {
                if (!self::privateFile($marker, $handle) || !fflush($handle)) {
                    return self::result(false, 'connection_validation_unavailable');
                }
            } finally { fclose($handle); }
            if (!self::sameStorage($privateRoot, $publicRoot, $root, $directory, $identity)) {
                return self::result(false, 'connection_validation_unavailable');
            }
            $deadline = hrtime(true) / 1e9 + 12.0;
            $adapter = $this->readOnlyAdapter($this->http ?? new CurlHttpAdapter(), $deadline);
            $checked = [];
            try {
                $client = new DiscordRestClient($adapter, PrivateConfig::APPLICATION_ID, $config['bot_token'], [
                    'enabled' => true, 'command_registration_enabled' => false, 'connection_ready' => false,
                    'timeout_ms' => 1500, 'connect_timeout_ms' => 500, 'deadline_monotonic' => $deadline,
                ]);
                $checked = (new BotValidationCheck($client))->run();
            } catch (\Throwable) { /* Only the fixed failure below may escape this boundary. */ }
            $checks = [];
            foreach (self::CHECKS as $key) { $checks[$key] = ($checked['checks'][$key] ?? null) === true; }
            $code = in_array($checked['code'] ?? null, self::RESULT_CODES, true) ? $checked['code'] : 'connection_validation_failed';
            $passed = ($checked['ok'] ?? null) === true && $code === 'bot_check_passed' && !in_array(false, $checks, true);
            if (!$passed && $code === 'bot_check_passed') { $code = 'connection_validation_failed'; }
            $bytes = json_encode(['status' => $passed ? 'passed' : 'failed', 'code' => $code,
                'checks' => $checks, 'checked_at_utc' => gmdate('Y-m-d\TH:i:s\Z')], JSON_THROW_ON_ERROR) . "\n";
            if (!self::sameStorage($privateRoot, $publicRoot, $root, $directory, $identity)) {
                return self::result(false, 'connection_validation_unavailable');
            }
            $temporary = $directory . DIRECTORY_SEPARATOR . '.connection-validation-' . bin2hex(random_bytes(12)) . '.tmp';
            PrivateConfig::requirePrivatePath($temporary, $publicRoot);
            $handle = fopen($temporary, 'x+b');
            if ($handle === false) { return self::result(false, 'connection_validation_unavailable'); }
            $temporaryCreated = true;
            try {
                $stat = fstat($handle);
                $temporaryIdentity = $stat === false ? null : [$stat['dev'], $stat['ino']];
                if (!self::privateFile($temporary, $handle) || fwrite($handle, $bytes) !== strlen($bytes) || !fflush($handle)) {
                    return self::result(false, 'connection_validation_unavailable');
                }
            } finally { fclose($handle); }
            if (!self::sameStorage($privateRoot, $publicRoot, $root, $directory, $identity)) {
                return self::result(false, 'connection_validation_unavailable');
            }
            PrivateConfig::requirePrivatePath($destination, $publicRoot);
            if (file_exists($destination) || !self::sameFile($temporary, $temporaryIdentity) || !rename($temporary, $destination)) {
                return self::result(false, 'connection_validation_unavailable');
            }
            $temporaryCreated = false;
            $temporary = null;
            return self::result($passed, $passed ? 'connection_validation_recorded' : 'connection_validation_failed');
        } catch (\Throwable) { return self::result(false, 'connection_validation_unavailable'); }
        finally {
            // If an ancestor changed, leave the original temporary file alone.
            // A path reached through a substituted directory is never ours to delete.
            if ($temporaryCreated && $temporary !== null) {
                try {
                    if (self::sameStorage($privateRoot, $publicRoot, $root, $directory, $identity) &&
                        self::sameFile($temporary, $temporaryIdentity)) { unlink($temporary); }
                } catch (\Throwable) { /* No cleanup through an unverified path. */ }
            }
            while (ob_get_level() > $bufferLevel) { ob_end_clean(); }
            restore_error_handler();
            umask($previousMask);
        }
    }

    /** A second, independent gate prevents this check ever dispatching mutations. */
    private function readOnlyAdapter(HttpAdapter $http, float $deadline): HttpAdapter
    {
        return new class($http, $deadline) implements HttpAdapter {
            private int $next = 0;
            public function __construct(private readonly HttpAdapter $http, private readonly float $deadline) {}
            public function exchange(HttpRequest $request): HttpResponse
            {
                $paths = ['/users/@me', '/oauth2/applications/@me', '/guilds/' . PrivateConfig::GUILD_ID,
                    '/guilds/' . PrivateConfig::GUILD_ID . '/members/' . PrivateConfig::APPLICATION_ID,
                    '/guilds/' . PrivateConfig::GUILD_ID . '/roles', '/channels/' . PrivateConfig::GUIDE_ID,
                    '/channels/' . PrivateConfig::RECORDS_ID, '/channels/' . PrivateConfig::VOICE_ID];
                $remaining = (int) floor(($this->deadline - hrtime(true) / 1e9) * 1000);
                if ($request->method() !== 'GET' || $request->body() !== '' || !isset($paths[$this->next]) ||
                    $request->url() !== 'https://discord.com/api/v10' . $paths[$this->next] || $remaining < 100) {
                    return new HttpResponse(0, [], '', 'transport_unavailable');
                }
                ++$this->next;
                $timeout = min(1500, $remaining, $request->timeoutMs);
                return $this->http->exchange(new HttpRequest('GET', $request->url(), $request->headers(), '',
                    $timeout, min(500, $timeout, $request->connectTimeoutMs), 1048576, true));
            }
        };
    }

    private static function storage(string $privateRoot, string $publicRoot): array
    {
        self::noLinks($privateRoot);
        $root = realpath($privateRoot);
        if ($root === false || !is_dir($root) || dirname($root) === $root) { throw new \RuntimeException('Unavailable'); }
        self::noLinks($root);
        if (PHP_OS_FAMILY !== 'Windows' && (fileperms($root) & 0077) !== 0) { throw new \RuntimeException('Unavailable'); }
        PrivateConfig::requirePrivatePath($root . DIRECTORY_SEPARATOR . '.connection-validation-boundary', $publicRoot);
        $directory = $root . DIRECTORY_SEPARATOR . 'var';
        self::noLinks($directory);
        if (!is_dir($directory) || realpath($directory) !== $directory) { throw new \RuntimeException('Unavailable'); }
        if (PHP_OS_FAMILY !== 'Windows' && (fileperms($directory) & 0077) !== 0) { throw new \RuntimeException('Unavailable'); }
        return [$root, $directory];
    }

    private static function noLinks(string $path): void
    {
        if ($path === '' || !(str_starts_with($path, '/') || preg_match('/\A[A-Za-z]:[\\\\\/]/', $path) === 1)) {
            throw new \RuntimeException('Unavailable');
        }
        do {
            clearstatcache(true, $path);
            if (is_link($path)) { throw new \RuntimeException('Unavailable'); }
            $parent = dirname($path);
            if ($parent === $path) { break; }
            $path = $parent;
        } while (true);
    }

    private static function directoryIdentity(string $directory): array
    {
        $stat = stat($directory);
        if ($stat === false) { throw new \RuntimeException('Unavailable'); }
        return [$stat['dev'], $stat['ino']];
    }

    private static function sameStorage(string $privateRoot, string $publicRoot, string $root, string $directory, array $identity): bool
    {
        return self::storage($privateRoot, $publicRoot) === [$root, $directory] && self::directoryIdentity($directory) === $identity;
    }

    private static function sameFile(string $path, ?array $identity): bool
    {
        clearstatcache(true, $path);
        $stat = lstat($path);
        return $identity !== null && $stat !== false && ($stat['mode'] & 0170000) === 0100000 &&
            [$stat['dev'], $stat['ino']] === $identity;
    }

    /** umask(0077) protects creation; verify chmod before any request or write. */
    private static function privateFile(string $path, mixed $handle): bool
    {
        if (is_link($path) || !chmod($path, 0600)) { return false; }
        $stat = fstat($handle);
        return $stat !== false && ($stat['mode'] & 0170000) === 0100000 &&
            (PHP_OS_FAMILY === 'Windows' || ($stat['mode'] & 0777) === 0600);
    }

    private static function result(bool $ok, string $code): array { return ['ok' => $ok, 'code' => $code]; }
}
