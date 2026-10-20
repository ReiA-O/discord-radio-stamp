<?php
declare(strict_types=1);
namespace RadioStamp\Http;

/**
 * Fixed private-storage diagnostics for an already authenticated owner check.
 * This class is not an HTTP entrypoint. Callers must keep their feature flag off
 * by default and must never derive these root arguments from HTTP input.
 * No configuration, existing database, environment, network, or shell is read.
 */
final class PrivateRuntimeCheck
{
    /** Pure capability flags: no filesystem, key generation, or network work. */
    public static function features(): array
    {
        return [
            'curl' => extension_loaded('curl') && function_exists('curl_init'),
            'gd' => extension_loaded('gd') && function_exists('imagecreatetruecolor') && function_exists('imagettftext'),
            'sodium' => extension_loaded('sodium') && function_exists('sodium_crypto_sign_verify_detached'),
            'pdo_sqlite' => extension_loaded('pdo_sqlite') && class_exists(\PDO::class, false),
            'sqlite_online_backup' => extension_loaded('sqlite3') && class_exists(\SQLite3::class, false) && method_exists(\SQLite3::class, 'backup'),
            'mbstring' => extension_loaded('mbstring') && function_exists('mb_strlen'),
            'fastcgi_finish_request' => function_exists('fastcgi_finish_request'),
            'symlink' => function_exists('symlink'),
        ];
    }

    /**
     * Every returned leaf is a boolean or a fixed enum; paths and exception text
     * never cross this boundary. Status describes the diagnostics, not readiness
     * to operate a Bot. Missing optional functions remain explicit booleans.
     */
    public static function run(string $privateRoot, string $publicRoot): array
    {
        $report = [
            'status' => 'skip',
            'features' => self::features(),
            'checks' => [
                'private_storage' => 'skip', 'private_write' => 'skip',
                'public_candidate_guard' => 'skip', 'dangling_symlink_guard' => 'skip',
                'scratch_permissions' => 'skip', 'cleanup' => 'skip',
            ],
            'reasons' => [],
        ];
        $createdDirectories = [];
        $createdFiles = [];
        $handle = null;
        $activeCheck = 'private_storage';
        $failureCode = 'private_root_unavailable';
        // Restore the caller's handler even when the filesystem rejects a probe.
        set_error_handler(static fn (): bool => true);
        try {
            if (!self::absolute($privateRoot) || is_link($privateRoot) || !is_dir($privateRoot)) {
                throw new \RuntimeException();
            }
            $private = realpath($privateRoot);
            if ($private === false || dirname($private) === $private) { throw new \RuntimeException(); }
            $failureCode = 'public_root_unavailable';
            if (!self::absolute($publicRoot) || !is_dir($publicRoot)) { throw new \RuntimeException(); }
            $public = realpath($publicRoot);
            if ($public === false) { throw new \RuntimeException(); }

            $failureCode = 'private_location_not_accepted';
            PrivateConfig::requirePrivatePath($private . '/.runtime-private-candidate', $public);
            $var = $private . '/var';
            PrivateConfig::requirePrivatePath($var, $public);
            $failureCode = 'private_var_unavailable';
            if (is_link($var)) { throw new \RuntimeException(); }
            if (!file_exists($var)) {
                if (!mkdir($var, 0700)) { throw new \RuntimeException(); }
                $createdDirectories[] = $var;
                if (PHP_OS_FAMILY !== 'Windows' && !chmod($var, 0700)) { throw new \RuntimeException(); }
            }
            if (!is_dir($var)) { throw new \RuntimeException(); }
            // Resolve and check the parent before creating the fresh directory.
            PrivateConfig::requirePrivatePath($var . '/.runtime-private-candidate', $public);
            $failureCode = 'scratch_creation_unavailable';
            $suffix = bin2hex(random_bytes(12));
            $scratch = $var . '/.runtime-check-' . $suffix;
            if (file_exists($scratch) || is_link($scratch) || !mkdir($scratch, 0700)) {
                throw new \RuntimeException();
            }
            $createdDirectories[] = $scratch;
            $failureCode = 'private_location_not_accepted';
            PrivateConfig::requirePrivatePath($scratch . '/future.sqlite', $public);
            $report['checks']['private_storage'] = 'pass';

            $activeCheck = 'scratch_permissions';
            $failureCode = 'scratch_permissions_unavailable';
            if (PHP_OS_FAMILY === 'Windows') {
                $report['reasons'][] = 'windows_permissions_not_measured';
            } else {
                if (!chmod($scratch, 0700)) { throw new \RuntimeException(); }
                clearstatcache(true, $scratch);
                $mode = fileperms($scratch);
                if ($mode === false || ($mode & 0777) !== 0700) { throw new \RuntimeException(); }
                $report['checks']['scratch_permissions'] = 'pass';
            }

            $activeCheck = 'private_write';
            $failureCode = 'private_write_unavailable';
            $file = $scratch . '/own-write-probe.tmp';
            $handle = fopen($file, 'x+b');
            if ($handle === false) { $handle = null; throw new \RuntimeException(); }
            $createdFiles[] = $file;
            $payload = 'radio-runtime-check';
            if (fwrite($handle, $payload) !== strlen($payload) || !fflush($handle)) {
                throw new \RuntimeException();
            }
            if (!fclose($handle)) { $handle = null; throw new \RuntimeException(); }
            $handle = null;
            $report['checks']['private_write'] = 'pass';

            $activeCheck = 'public_candidate_guard';
            $failureCode = 'public_candidate_not_rejected';
            $rejected = false;
            try {
                // Metadata-only candidate: never create anything in public_html.
                PrivateConfig::requirePrivatePath($public . '/.runtime-rejected-' . $suffix . '.sqlite', $public);
            } catch (\InvalidArgumentException) { $rejected = true; }
            if (!$rejected) { throw new \RuntimeException(); }
            $report['checks']['public_candidate_guard'] = 'pass';

            $activeCheck = 'dangling_symlink_guard';
            $failureCode = 'dangling_fixture_not_established';
            if (PHP_OS_FAMILY === 'Windows') {
                $report['reasons'][] = 'windows_symlink_not_measured';
            } elseif (!function_exists('symlink')) {
                $report['reasons'][] = 'symlink_function_unavailable';
            } else {
                $link = $scratch . '/own-dangling-link';
                $target = $scratch . '/deliberately-missing-target';
                if (!symlink($target, $link)) {
                    $report['reasons'][] = 'symlink_creation_unavailable';
                } else {
                    $createdFiles[] = $link;
                    clearstatcache(true, $link);
                    if (!is_link($link) || file_exists($link)) { throw new \RuntimeException(); }
                    $failureCode = 'dangling_symlink_not_rejected';
                    $rejected = false;
                    try { PrivateConfig::requirePrivatePath($link, $public); }
                    catch (\InvalidArgumentException) { $rejected = true; }
                    if (!$rejected) { throw new \RuntimeException(); }
                    $report['checks']['dangling_symlink_guard'] = 'pass';
                }
            }
        } catch (\Throwable) {
            $report['checks'][$activeCheck] = 'fail';
            $report['reasons'][] = $failureCode;
        } finally {
            $clean = true;
            try {
                try {
                    if (is_resource($handle) && !fclose($handle)) { $clean = false; }
                } catch (\Throwable) { $clean = false; }
                foreach (array_reverse($createdFiles) as $file) {
                    // Exact self-created names only; unlink never follows a symlink.
                    try { if (!unlink($file)) { $clean = false; } }
                    catch (\Throwable) { $clean = false; }
                }
                foreach (array_reverse($createdDirectories) as $directory) {
                    // Unexpected contents or a replaced directory are never traversed.
                    try {
                        clearstatcache(true, $directory);
                        if (is_link($directory) || !rmdir($directory)) { $clean = false; }
                    } catch (\Throwable) { $clean = false; }
                }
            } finally {
                restore_error_handler();
            }
            $report['checks']['cleanup'] = $clean ? 'pass' : 'fail';
            if (!$clean) { $report['reasons'][] = 'own_fixture_cleanup_incomplete'; }
            $report['status'] = in_array('fail', $report['checks'], true) ? 'fail'
                : (in_array('skip', $report['checks'], true) ? 'skip' : 'pass');
        }
        return $report;
    }

    private static function absolute(string $path): bool
    {
        return str_starts_with($path, '/') || preg_match('/\A[A-Za-z]:[\\\\\/]/', $path) === 1;
    }
}
