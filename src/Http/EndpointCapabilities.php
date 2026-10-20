<?php
declare(strict_types=1);
namespace RadioStamp\Http;

/** Fixed, credential-free feature receipt; public composition saves it after PONG finishes. */
final class EndpointCapabilities
{
    public static function save(string $privateRoot, string $publicRoot): bool
    {
        $temporary = null;
        $temporaryCreated = false;
        $previousMask = umask(0077);
        set_error_handler(static fn(): bool => true);
        try {
            $root = realpath($privateRoot);
            if ($root === false || is_link($privateRoot)) { return false; }
            $directory = $root . DIRECTORY_SEPARATOR . 'var';
            if (!is_dir($directory) || is_link($directory)) { return false; }
            $destination = $directory . DIRECTORY_SEPARATOR . 'endpoint-capabilities.json';
            PrivateConfig::requirePrivatePath($destination, $publicRoot);
            if (file_exists($destination) && !is_file($destination)) { return false; }
            $features = PrivateRuntimeCheck::features();
            $features['curl_async_dns'] = extension_loaded('curl') && defined('CURL_VERSION_ASYNCHDNS') && (((curl_version()['features'] ?? 0) & CURL_VERSION_ASYNCHDNS) !== 0);
            $bytes = json_encode($features, JSON_THROW_ON_ERROR) . "\n";
            $temporary = $directory . DIRECTORY_SEPARATOR . '.endpoint-capabilities-' . bin2hex(random_bytes(8)) . '.tmp';
            PrivateConfig::requirePrivatePath($temporary, $publicRoot);
            $handle = fopen($temporary, 'x+b');
            if ($handle === false) { return false; }
            $temporaryCreated = true;
            try {
                if (fwrite($handle, $bytes) !== strlen($bytes) || !fflush($handle)) { return false; }
            } finally { fclose($handle); }
            if (!chmod($temporary, 0600) || !rename($temporary, $destination)) { return false; }
            $temporary = null;
            $temporaryCreated = false;
            return true;
        } catch (\Throwable) { return false; }
        finally {
            if ($temporaryCreated && $temporary !== null && is_file($temporary)) { @unlink($temporary); }
            restore_error_handler();
            umask($previousMask);
        }
    }
}
