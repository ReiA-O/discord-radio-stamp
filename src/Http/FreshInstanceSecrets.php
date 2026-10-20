<?php
declare(strict_types=1);
namespace RadioStamp\Http;

/** Fixed token and fresh-instance key only; no configurable storage path. */
final class FreshInstanceSecrets
{
    public const PRIVATE_ROOT = '/home/hosting-user/hosting-user.example.invalid/radio-stamp-private';
    public const PUBLIC_ROOT = '/home/hosting-user/hosting-user.example.invalid/public_html';
    public const SECRET_DIRECTORY = self::PRIVATE_ROOT . '/secrets';
    public const INSTANCE_DIRECTORY = self::PRIVATE_ROOT . '/instances/discord-fresh-20261017-v1';
    public const TOKEN_PATH = self::SECRET_DIRECTORY . '/bot-token.txt';
    public const QUEUE_KEY_PATH = self::INSTANCE_DIRECTORY . '/queue-key.hex';
    public const DATABASE_PATH = self::INSTANCE_DIRECTORY . '/radio.sqlite';
    public const INITIALIZED_PATH = self::INSTANCE_DIRECTORY . '/initialized.json';

    public function __debugInfo(): array { return ['storage' => 'fixed_fresh_instance', 'credentials' => '[private]']; }

    public function read(): array
    {
        $handles = [];
        $bufferLevel = ob_get_level();
        set_error_handler(static fn(): bool => true);
        try {
            if (!ob_start(static fn(string $discarded): string => '')) { self::unavailable(); }
            $owner = getmyuid();
            if (!is_int($owner) || $owner < 0 || (function_exists('posix_geteuid') && posix_geteuid() !== $owner)) { self::unavailable(); }
            $public = realpath(self::PUBLIC_ROOT);
            if (!is_string($public) || $public === '' || $public[0] !== '/') { self::unavailable(); }
            $directories = self::directories($owner, $public);
            $before = [];
            foreach ([self::TOKEN_PATH, self::QUEUE_KEY_PATH] as $path) { $before[$path] = self::file($path, $owner, $public); }
            foreach ([self::TOKEN_PATH, self::QUEUE_KEY_PATH] as $path) {
                $handle = fopen($path, 'rb');
                if ($handle === false) { self::unavailable(); }
                $handles[$path] = $handle;
                if (!flock($handle, LOCK_SH | LOCK_NB)) { self::unavailable(); }
                self::sameHandle($handle, $before[$path]);
                if (self::file($path, $owner, $public) !== $before[$path]) { self::unavailable(); }
            }
            if (self::directories($owner, $public) !== $directories) { self::unavailable(); }
            foreach ($handles as $path => $handle) {
                self::sameHandle($handle, $before[$path]);
                if (self::file($path, $owner, $public) !== $before[$path]) { self::unavailable(); }
            }
            $raw = [];
            foreach ($handles as $path => $handle) { $raw[$path] = self::readExact($handle, $before[$path]); }
            foreach ($handles as $path => $handle) {
                if (self::readExact($handle, $before[$path]) !== $raw[$path]) { self::unavailable(); }
            }
            if (self::directories($owner, $public) !== $directories) { self::unavailable(); }
            foreach ($handles as $path => $handle) {
                self::sameHandle($handle, $before[$path]);
                if (self::file($path, $owner, $public) !== $before[$path]) { self::unavailable(); }
            }
            return ['bot_token' => self::value($raw[self::TOKEN_PATH], false),
                'token_encryption_key_hex' => self::value($raw[self::QUEUE_KEY_PATH], true)];
        } catch (\Throwable) {
            throw new \RuntimeException('Fresh-instance configuration is unavailable.');
        } finally {
            foreach ($handles as $handle) {
                try { flock($handle, LOCK_UN); } catch (\Throwable) { /* No logging. */ }
                try { fclose($handle); } catch (\Throwable) { /* No logging. */ }
            }
            while (ob_get_level() > $bufferLevel) { ob_end_clean(); }
            restore_error_handler();
        }
    }

    private static function directories(int $owner, string $public): array
    {
        $result = [];
        foreach ([self::SECRET_DIRECTORY, self::INSTANCE_DIRECTORY] as $branch) {
            $path = $branch;
            do {
                clearstatcache(true, $path);
                $stat = lstat($path);
                if (!is_array($stat) || !self::statShape($stat) || ($stat['mode'] & 0170000) !== 0040000 ||
                    ($stat['mode'] & 0022) !== 0 || realpath($path) !== $path) { self::unavailable(); }
                if ($path === self::PRIVATE_ROOT || str_starts_with($path, self::PRIVATE_ROOT . '/')) {
                    if ($stat['uid'] !== $owner || ($stat['mode'] & 07777) !== 0700 || !self::outsidePublic($path, $public)) { self::unavailable(); }
                }
                $stamp = [$stat['dev'], $stat['ino'], $stat['uid'], $stat['mode']];
                if (isset($result[$path]) && $result[$path] !== $stamp) { self::unavailable(); }
                $result[$path] = $stamp;
                $parent = dirname($path);
                if ($parent === $path) { break; }
                $path = $parent;
            } while (true);
        }
        ksort($result);
        return $result;
    }

    private static function file(string $path, int $owner, string $public): array
    {
        if (!in_array($path, [self::TOKEN_PATH, self::QUEUE_KEY_PATH], true)) { self::unavailable(); }
        clearstatcache(true, $path);
        $stat = lstat($path);
        if (!is_array($stat) || !self::statShape($stat) || ($stat['mode'] & 0170000) !== 0100000 ||
            ($stat['mode'] & 07777) !== 0600 || $stat['uid'] !== $owner || $stat['nlink'] !== 1 ||
            realpath($path) !== $path || !self::outsidePublic($path, $public)) { self::unavailable(); }
        return self::stamp($stat);
    }

    private static function outsidePublic(string $path, string $public): bool
    {
        $public = rtrim($public, '/');
        return $path !== $public && !str_starts_with($path, $public . '/');
    }

    private static function statShape(array $stat): bool
    {
        foreach (['dev', 'ino', 'uid', 'mode', 'nlink', 'size', 'mtime', 'ctime'] as $key) {
            if (!is_int($stat[$key] ?? null)) { return false; }
        }
        return $stat['dev'] >= 0 && $stat['ino'] > 0 && $stat['uid'] >= 0 && $stat['size'] >= 0;
    }

    private static function stamp(array $stat): array
    {
        return ['dev' => $stat['dev'], 'ino' => $stat['ino'], 'uid' => $stat['uid'], 'mode' => $stat['mode'],
            'nlink' => $stat['nlink'], 'size' => $stat['size'], 'mtime' => $stat['mtime'], 'ctime' => $stat['ctime']];
    }

    private static function sameHandle(mixed $handle, array $before): void
    {
        $stat = fstat($handle);
        if (!is_array($stat) || !self::statShape($stat) || self::stamp($stat) !== $before) { self::unavailable(); }
    }

    private static function readExact(mixed $handle, array $before): string
    {
        self::sameHandle($handle, $before);
        if (!rewind($handle)) { self::unavailable(); }
        $bytes = stream_get_contents($handle, 513);
        if (!is_string($bytes) || strlen($bytes) !== $before['size']) { self::unavailable(); }
        self::sameHandle($handle, $before);
        return $bytes;
    }

    private static function value(#[\SensitiveParameter] string $raw, bool $queueKey): string
    {
        if (str_ends_with($raw, "\r\n")) { $value = substr($raw, 0, -2); }
        elseif (str_ends_with($raw, "\n")) { $value = substr($raw, 0, -1); }
        else { $value = $raw; }
        $pattern = $queueKey ? '/\A[0-9a-fA-F]{64}\z/D' : '/\A[A-Za-z0-9._-]{20,256}\z/D';
        if (preg_match($pattern, $value) !== 1) { self::unavailable(); }
        return $value;
    }

    private static function unavailable(): never { throw new \RuntimeException('Fresh-instance configuration is unavailable.'); }
}
