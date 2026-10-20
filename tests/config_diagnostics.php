<?php
declare(strict_types=1);
require_once __DIR__.'/artifact_directory.php';
require dirname(__DIR__) . '/bootstrap.php';

use RadioStamp\Discord\HttpAdapter;
use RadioStamp\Discord\HttpRequest;
use RadioStamp\Discord\HttpResponse;
use RadioStamp\Http\ConnectionValidationReceipt;
use RadioStamp\Http\PostResponseDriver;
use RadioStamp\Http\PrivateConfig;
use RadioStamp\Http\PublicEndpoint;
use RadioStamp\Http\ValidationRuntime;

// Dedicated fictional fixtures only. Never load config/admin.php or staging.
$diagnosticChecks = 0;
$diagnosticFailure = false;
function diagnosticCheck(bool $condition, string $description): void
{
    global $diagnosticChecks;
    if (!$condition) { throw new RuntimeException('Configuration diagnostic assertion failed: ' . $description); }
    ++$diagnosticChecks;
}
function diagnosticSame(mixed $expected, mixed $actual, string $description): void { diagnosticCheck($expected === $actual, $description); }

final class DiagnosticDriver implements PostResponseDriver
{
    public int $finishCalls = 0;
    public function __construct(public bool $throwAvailable = false, public int $throwCode = 0) {}
    public function available(): bool
    {
        if ($this->throwAvailable) { throw new RuntimeException('FICTIONAL_DRIVER_SECRET_NOT_REAL', $this->throwCode); }
        return true;
    }
    public function finish(): bool { ++$this->finishCalls; return true; }
}
final class DiagnosticRuntime implements ValidationRuntime
{
    public int $calls = 0;
    public function runInteraction(array $config, string $interactionId, string $privateRoot, string $publicRoot): array { ++$this->calls; return ['ok' => false]; }
    public function registerCommands(array $config): array { ++$this->calls; return ['ok' => false]; }
    public function inlineCheck(array $config, float $deadline): array { ++$this->calls; return ['ok' => false]; }
}
final class DiagnosticHttp implements HttpAdapter
{
    public int $calls = 0;
    public function exchange(HttpRequest $request): HttpResponse { ++$this->calls; throw new RuntimeException('Unexpected offline HTTP'); }
}

$base = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'radio-config-diagnostics-' . bin2hex(random_bytes(8));
mkdir($base, 0700);
$secret = 'FICTIONAL_CONFIG_SECRET_NOT_REAL_9cb614';
$fictionalPair = sodium_crypto_sign_seed_keypair(hash('sha256', 'FICTIONAL_DIAGNOSTIC_SIGNING_SEED_NOT_REAL', true));
$fictionalPublic = bin2hex(sodium_crypto_sign_publickey($fictionalPair));
$fixtureNumber = 0;
$makeFixture = static function () use ($base, $fictionalPublic, $secret, &$fixtureNumber): array {
    $root = $base . '/case-' . ++$fixtureNumber;
    $private = $root . '/synthetic-private'; $public = $root . '/public_html';
    foreach ([$root, $private, $private . '/config', $private . '/var', $public] as $directory) { mkdir($directory, 0700); }
    $config = ['mode' => 'admin_validation', 'admin_validation_enabled' => true,
        'application_id' => PrivateConfig::APPLICATION_ID, 'guild_id' => PrivateConfig::GUILD_ID,
        'admin_user_ids' => [PrivateConfig::OWNER_ID], 'public_key_hex' => $fictionalPublic,
        'bot_token' => 'FICTIONAL_DIAGNOSTIC_BOT_TOKEN_NOT_REAL',
        'token_encryption_key_hex' => hash('sha256', 'FICTIONAL_DIAGNOSTIC_ENCRYPTION_KEY_NOT_REAL'),
        'private_db_path' => $private . '/var/fictional.sqlite',
        'text_channel_id' => PrivateConfig::GUIDE_ID, 'records_channel_id' => PrivateConfig::RECORDS_ID,
        'voice_channel_id' => PrivateConfig::VOICE_ID, 'timezone' => 'Asia/Tokyo',
        'member_actions_enabled' => false, 'live_automation_enabled' => false, 'role_ids' => [],
        'runtime_check_enabled' => true, 'command_registration_enabled' => false,
        'fictional_extra_secret' => $secret];
    return ['root' => $root, 'private' => $private, 'public' => $public,
        'file' => $private . '/config/fictional-diagnostic-fixture.php', 'config' => $config];
};
$sourceFor = static fn(array $config): string => '<?php return ' . var_export($config, true) . ';';
$save = static function (array $fixture, string $source): void {
    file_put_contents($fixture['file'], $source); chmod($fixture['file'], 0600);
};
$forbidden = [$secret, 'FICTIONAL_', $base, 'RuntimeException', 'ParseError', 'Stack trace',
    'private_db_path', 'bot_token', 'token_encryption_key_hex', '1101', '1102', '1103', '1104', '1105', '1106', '1107', '1108', '1109', '1110', '1111', '1112'];
$checkPublicBytes = static function (string $bytes) use ($forbidden): void {
    foreach ($forbidden as $value) { diagnosticCheck(!str_contains($bytes, $value), 'public bytes exclude confidential or exception information'); }
};
$invoke = static function (array $fixture, ?string $path, string $class = PublicEndpoint::class, ?DiagnosticDriver $driver = null, ?array $server = null, string $raw = '', int $expectedFinishCalls = 0) use ($checkPublicBytes): array {
    $driver ??= new DiagnosticDriver(); $runtime = new DiagnosticRuntime(); $http = new DiagnosticHttp();
    $endpoint = new $class($fixture['public'], $driver, $runtime, new ConnectionValidationReceipt($http));
    $level = ob_get_level(); ob_start();
    try {
        $delivery = $endpoint->prepare($server ?? ['REQUEST_METHOD' => 'GET', 'REMOTE_ADDR' => '203.0.113.8'], $raw, $path);
        diagnosticCheck($delivery->deliver($driver), 'fixed response delivery succeeds');
        $bytes = (string) ob_get_contents();
    } finally { while (ob_get_level() > $level) { ob_end_clean(); } }
    diagnosticSame(json_encode($delivery->response->body, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR), $bytes, 'only the response JSON reaches stdout');
    $checkPublicBytes($bytes);
    diagnosticSame($expectedFinishCalls, $driver->finishCalls, 'only a valid capability PING finishes its response before a local feature receipt');
    diagnosticSame(0, $runtime->calls, 'diagnostic response invokes no application runtime');
    diagnosticSame(0, $http->calls, 'diagnostic response sends zero HTTP requests');
    diagnosticSame(null, $delivery->response->interactionId, 'diagnostic response does not enqueue an interaction');
    diagnosticCheck(!file_exists($fixture['config']['private_db_path']), 'diagnostics create no database');
    diagnosticCheck(!file_exists($fixture['private'] . '/var/connection-validation-20261017.attempt'), 'diagnostics create no connection attempt');
    diagnosticCheck(!file_exists($fixture['private'] . '/var/connection-validation-20261017.json'), 'diagnostics create no connection receipt');
    return [$delivery->response->status, $delivery->response->body];
};

try {
    // A test-only namespaced proxy reaches ob_start(false) without changing source.
    // All ordinary calls delegate to PHP's normal output buffer implementation.
    $shim = $base . '/fictional-output-guard-shim.php';
    file_put_contents($shim, <<<'PHP'
<?php
namespace RadioStamp\Http;
function ob_start(?callable $callback = null, int $chunkSize = 0, int $flags = PHP_OUTPUT_HANDLER_STDFLAGS): bool {
    if (($GLOBALS['fictionalDiagnosticOutputGuardFailure'] ?? false) === true) { return false; }
    return \ob_start($callback, $chunkSize, $flags);
}
PHP);
    require $shim;

    // OFF is an exact one-constant temp variant of trusted, non-secret source.
    $endpointPath = dirname(__DIR__) . '/src/Http/PublicEndpoint.php';
    $originalSource = file_get_contents($endpointPath); $sourceHash = hash('sha256', $originalSource);
    $offSource = str_replace('final class PublicEndpoint', 'final class PublicEndpointDiagnosticsOffFixture', $originalSource, $classReplacements);
    $offSource = str_replace('private const ALLOW_FIXED_DIAGNOSTICS = true;', 'private const ALLOW_FIXED_DIAGNOSTICS = false;', $offSource, $flagReplacements);
    diagnosticSame(1, $classReplacements, 'OFF fixture renames exactly one class');
    diagnosticSame(1, $flagReplacements, 'OFF fixture changes exactly one diagnostic constant');
    diagnosticCheck(str_contains($originalSource, 'private const ALLOW_CONNECTION_RECEIPT = false;'), 'connection receipt hook remains source-disabled');
    diagnosticCheck(str_contains($originalSource, 'private const ALLOW_PING_COMMAND_REGISTRATION = false;'), 'PING registration remains source-disabled');
    $variant = $base . '/fictional-endpoint-diagnostics-off.php'; file_put_contents($variant, $offSource); require $variant;
    $offClass = 'RadioStamp\\Http\\PublicEndpointDiagnosticsOffFixture';

    $cases = [
        ['path_guard', 1101, 'configuration_path_rejected'], ['missing', 1102, 'configuration_file_unavailable'],
        ['guard_failure', 1103, 'configuration_output_guard_unavailable'], ['parse', 1104, 'configuration_parse_failed'],
        ['throw', 1105, 'configuration_require_failed'], ['echo_throw', 1105, 'configuration_require_failed'],
        ['warning_throw', 1105, 'configuration_require_failed'], ['spoof_code', 1105, 'configuration_require_failed'],
        ['output', 1106, 'configuration_output_detected'], ['nested_output', 1106, 'configuration_output_detected'],
        ['bom_and_output', 1106, 'configuration_output_detected'], ['bom', 1107, 'configuration_bom_detected'],
        ['nonarray', 1108, 'configuration_not_array'], ['signature', 1109, 'configuration_scope_unavailable'],
        ['db_type', 1111, 'configuration_storage_type_invalid'], ['db_public', 1112, 'configuration_storage_path_rejected'],
        ['db_relative', 1112, 'configuration_storage_path_rejected'],
    ];
    foreach ($cases as [$name, $code, $diagnostic]) {
        $fixture = $makeFixture(); $config = $fixture['config']; $path = $fixture['file'];
        $source = $sourceFor($config);
        switch ($name) {
            case 'path_guard': $path = $fixture['public'] . '/fictional-config.php'; break;
            case 'missing': $path = $fixture['private'] . '/config/fictional-missing.php'; break;
            case 'parse': $source = '<?php $fictional = ' . var_export($secret, true) . '; return [;'; break;
            case 'throw': $source = '<?php (static function () { throw new \\RuntimeException(' . var_export($secret, true) . '); })();'; break;
            case 'echo_throw': $source = '<?php echo ' . var_export($secret, true) . '; throw new \\RuntimeException(' . var_export($secret, true) . ');'; break;
            case 'warning_throw': $source = '<?php trigger_error(' . var_export($secret, true) . ', E_USER_WARNING); throw new \\RuntimeException(' . var_export($secret, true) . ');'; break;
            case 'spoof_code': $source = '<?php throw new \\RuntimeException(' . var_export($secret, true) . ', 1104);'; break;
            case 'output': $source = '<?php echo ' . var_export($secret, true) . '; return ' . var_export($config, true) . ';'; break;
            case 'nested_output': $source = '<?php ob_start(); echo ' . var_export($secret, true) . '; return ' . var_export($config, true) . ';'; break;
            case 'bom_and_output': $source = "\xEF\xBB\xBF" . '<?php echo ' . var_export($secret, true) . '; return ' . var_export($config, true) . ';'; break;
            case 'bom': $source = "\xEF\xBB\xBF" . $source; break;
            case 'nonarray': $source = '<?php return ' . var_export($secret, true) . ';'; break;
            case 'signature': $config['public_key_hex'] = $secret; $source = $sourceFor($config); break;
            case 'db_type': $config['private_db_path'] = ['fictional' => $secret]; $source = $sourceFor($config); break;
            case 'db_public': $config['private_db_path'] = $fixture['public'] . '/fictional.sqlite'; $source = $sourceFor($config); break;
            case 'db_relative': $config['private_db_path'] = 'fictional-relative.sqlite'; $source = $sourceFor($config); break;
        }
        $save($fixture, $source); $snapshot = hash_file('sha256', $fixture['file']);
        $GLOBALS['fictionalDiagnosticOutputGuardFailure'] = $name === 'guard_failure';
        $level = ob_get_level(); ob_start(); $errorCalls = 0;
        $testHandler = static function () use (&$errorCalls): bool { ++$errorCalls; return true; };
        set_error_handler($testHandler);
        try {
            $failure = null;
            try { PrivateConfig::load($path, $fixture['public'], false); } catch (Throwable $caught) { $failure = $caught; }
            diagnosticCheck($failure instanceof RuntimeException, 'configuration stage becomes a fixed RuntimeException');
            diagnosticSame($code, $failure->getCode(), 'configuration stage has its documented internal code');
            diagnosticSame(null, $failure->getPrevious(), 'underlying exception and stack are not retained');
            diagnosticCheck(!str_contains($failure->getMessage(), $secret), 'wrapped exception message excludes fixture secret');
            diagnosticSame($level + 1, ob_get_level(), 'configuration loader restores the caller buffer');
            diagnosticSame('', ob_get_contents(), 'configuration loader emits no stdout');
            diagnosticSame(0, $errorCalls, 'configuration warning never reaches the caller error handler');
            diagnosticSame($testHandler, set_error_handler($testHandler), 'configuration loader restores the caller error handler');
            restore_error_handler();
        } finally { restore_error_handler(); while (ob_get_level() > $level) { ob_end_clean(); } }
        diagnosticSame([503, ['error' => 'administrator_validation_unavailable', 'diagnostic' => $diagnostic]], $invoke($fixture, $path), 'ON response has one fixed diagnostic enum');
        diagnosticSame([503, ['error' => 'administrator_validation_unavailable']], $invoke($fixture, $path, $offClass), 'OFF response retains only the generic error');
        $GLOBALS['fictionalDiagnosticOutputGuardFailure'] = false;
        diagnosticSame($snapshot, hash_file('sha256', $fixture['file']), 'configuration fixture remains unchanged');
    }

    foreach ([null, ''] as $path) {
        $fixture = $makeFixture(); $save($fixture, $sourceFor($fixture['config']));
        diagnosticSame([503, ['error' => 'administrator_validation_unavailable', 'diagnostic' => 'configuration_file_unavailable']], $invoke($fixture, $path), 'unset configuration path is a fixed unavailable stage');
        diagnosticSame([503, ['error' => 'administrator_validation_unavailable']], $invoke($fixture, $path, $offClass), 'OFF suppresses unset-path diagnostics');
    }

    // Queue-secret readiness is not made a GET/config diagnostic oracle.
    foreach (['bot_token', 'token_encryption_key_hex'] as $key) {
        foreach (['missing', 'null', 'malformed', 'wrong_type'] as $value) {
            $fixture = $makeFixture(); $config = $fixture['config'];
            if ($value === 'missing') { unset($config[$key]); }
            else { $config[$key] = match ($value) { 'null' => null, 'malformed' => $secret . '!', default => [$secret] }; }
            $save($fixture, $sourceFor($config));
            $failure = null;
            try { PrivateConfig::load($fixture['file'], $fixture['public'], true); } catch (Throwable $caught) { $failure = $caught; }
            diagnosticCheck($failure instanceof RuntimeException && $failure->getCode() === 1110, 'explicit queue readiness has its fixed internal code');
            diagnosticSame(null, $failure->getPrevious(), 'queue readiness keeps no previous exception');
            diagnosticSame($config, PrivateConfig::load($fixture['file'], $fixture['public'], false), 'signature-only load preserves existing readiness separation');
            diagnosticSame([405, ['error' => 'method_not_allowed']], $invoke($fixture, $fixture['file']), 'unconfigured queue secrets retain GET 405');
            diagnosticSame([405, ['error' => 'method_not_allowed']], $invoke($fixture, $fixture['file'], $offClass), 'OFF retains GET 405 for queue secrets');
        }
    }

    foreach ([0, -1, 98765] as $code) {
        $fixture = $makeFixture(); $save($fixture, $sourceFor($fixture['config']));
        diagnosticSame([503, ['error' => 'administrator_validation_unavailable', 'diagnostic' => 'diagnostic_unknown']], $invoke($fixture, $fixture['file'], PublicEndpoint::class, new DiagnosticDriver(true, $code)), 'unknown failures expose only the unknown enum');
        diagnosticSame([503, ['error' => 'administrator_validation_unavailable']], $invoke($fixture, $fixture['file'], $offClass, new DiagnosticDriver(true, $code)), 'OFF suppresses unknown failure details');
    }

    $fixture = $makeFixture(); $save($fixture, $sourceFor($fixture['config']));
    diagnosticSame([405, ['error' => 'method_not_allowed']], $invoke($fixture, $fixture['file']), 'extra array secret never changes a valid GET response');
    $raw = json_encode(['type' => 1, 'application_id' => PrivateConfig::APPLICATION_ID], JSON_THROW_ON_ERROR);
    $stamp = (string) time(); $server = ['REQUEST_METHOD' => 'POST', 'REMOTE_ADDR' => '203.0.113.8', 'HTTP_X_SIGNATURE_TIMESTAMP' => $stamp,
        'HTTP_X_SIGNATURE_ED25519' => bin2hex(sodium_crypto_sign_detached($stamp . $raw, sodium_crypto_sign_secretkey($fictionalPair)))];
    foreach ([PublicEndpoint::class, $offClass] as $endpointClass) {
        foreach ([false, true] as $registrationFlag) {
            $config = $fixture['config']; $config['command_registration_enabled'] = $registrationFlag;
            $save($fixture, $sourceFor($config));
            diagnosticSame([200, ['type' => 1]], $invoke($fixture, $fixture['file'], $endpointClass, null, $server, $raw, 1), 'valid signed PING stays type one with both network hooks source-disabled');
        }
    }
    $server['HTTP_X_SIGNATURE_ED25519'] = str_repeat('0', 128);
    diagnosticSame([401, ['error' => 'invalid_signature']], $invoke($fixture, $fixture['file'], PublicEndpoint::class, null, $server, $raw), 'invalid signature retains authentication failure');
    diagnosticSame($sourceHash, hash_file('sha256', $endpointPath), 'OFF testing never changes actual endpoint source');
} catch (Throwable) {
    // Never print exception strings/stacks or fictional secrets on test failure.
    $diagnosticFailure = true;
} finally {
    unset($GLOBALS['fictionalDiagnosticOutputGuardFailure']);
    $owned = realpath($base);
    if ($owned !== false && dirname($owned) === realpath(sys_get_temp_dir()) && str_starts_with(basename($owned), 'radio-config-diagnostics-')) {
        $remove = static function (string $directory) use (&$remove): void {
            foreach (new DirectoryIterator($directory) as $entry) {
                if ($entry->isDot()) { continue; }
                if ($entry->isLink()) { unlink($entry->getPathname()); }
                elseif ($entry->isDir()) { $remove($entry->getPathname()); rmdir($entry->getPathname()); }
                else { unlink($entry->getPathname()); }
            }
        };
        $remove($owned); rmdir($owned);
    }
}
if ($diagnosticFailure) { fwrite(STDERR, "Configuration diagnostics: failed.\n"); exit(1); }
echo 'Configuration diagnostics: ' . $diagnosticChecks . " assertions passed (offline fixtures only).\n";
