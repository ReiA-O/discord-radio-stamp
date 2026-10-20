<?php
declare(strict_types=1);
require_once __DIR__.'/artifact_directory.php';

// Offline copies only. The deployment entry's fixed bootstrap literal is the
// only source text replaced. The real Linux configuration is never opened.
$entryAssertions = 0;
function entryDiagnosticCheck(bool $condition, string $message): void
{
    global $entryAssertions;
    if (!$condition) { throw new RuntimeException('Entry diagnostic test failed: ' . $message); }
    $entryAssertions++;
}
function entryDiagnosticSame(mixed $expected, mixed $actual, string $message): void
{
    entryDiagnosticCheck($expected === $actual, $message);
}

$private = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'radio-entry-diagnostic-' . bin2hex(random_bytes(8));
mkdir($private, 0700);
$fakeBootstrap = $private . DIRECTORY_SEPARATOR . 'fictional-bootstrap.php';
$fictionalSecret = 'FICTIONAL_TEST_EXCEPTION_SECRET_NOT_REAL';
$originalBootstrap = "'/home/hosting-user/hosting-user.example.invalid/radio-stamp-private/releases/connect-20261017/bootstrap.php'";
$stub = <<<'PHP'
<?php
declare(strict_types=1);
namespace RadioStamp\Http;

// These stubs intentionally never inspect any supplied path or server input.
// No production bootstrap, autoloader, configuration, or transport is loaded.
$GLOBALS['entry_trace'][] = 'entry_bootstrap';
if ($GLOBALS['entry_failure'] === 'entry_bootstrap') { throw new \RuntimeException($GLOBALS['fictional_secret']); }
final class FastCgiPostResponseDriver
{
    public function __construct()
    {
        $GLOBALS['entry_trace'][] = 'entry_driver';
        if ($GLOBALS['entry_failure'] === 'entry_driver') { throw new \RuntimeException($GLOBALS['fictional_secret']); }
    }
}
final class PublicEndpoint
{
    public function __construct(mixed $ignoredPublicRoot, mixed $ignoredDriver)
    {
        $GLOBALS['entry_trace'][] = 'entry_endpoint_constructor';
        if ($GLOBALS['entry_failure'] === 'entry_endpoint_constructor') { throw new \Error($GLOBALS['fictional_secret']); }
    }
    public function prepare(mixed $ignoredServer, mixed $ignoredBody, mixed $ignoredConfigPath): FictionalEntryDelivery
    {
        // In particular, ignoredConfigPath is not read, retained, or executed.
        $GLOBALS['entry_trace'][] = 'entry_prepare';
        if ($GLOBALS['entry_failure'] === 'entry_prepare') { throw new \RuntimeException($GLOBALS['fictional_secret']); }
        return new FictionalEntryDelivery();
    }
}
final class FictionalEntryDelivery
{
    public function deliver(mixed $ignoredDriver): void
    {
        $GLOBALS['entry_trace'][] = 'entry_deliver';
        if ($GLOBALS['entry_failure'] === 'entry_deliver') { throw new \Error($GLOBALS['fictional_secret']); }
        http_response_code(200);
        echo json_encode(['type' => 1], JSON_THROW_ON_ERROR);
    }
}
PHP;
file_put_contents($fakeBootstrap, $stub);
$childFile = $private . DIRECTORY_SEPARATOR . 'fictional-child.php';
$stages = ['entry_bootstrap', 'entry_driver', 'entry_endpoint_constructor', 'entry_prepare', 'entry_deliver'];
$runCopy = static function (string $copy, string $failure) use ($childFile, $fictionalSecret): array {
    $source = '<?php declare(strict_types=1);' . "\n" .
        '$GLOBALS[\'entry_trace\'] = []; $GLOBALS[\'entry_failure\'] = ' . var_export($failure, true) . ';' . "\n" .
        '$GLOBALS[\'fictional_secret\'] = ' . var_export($fictionalSecret, true) . ';' . "\n" .
        '$before = ob_get_level(); ob_start(); require ' . var_export($copy, true) . '; $captured = ob_get_clean();' . "\n" .
        'echo json_encode([\'status\' => http_response_code(), \'stdout\' => $captured, \'trace\' => $GLOBALS[\'entry_trace\'], \'buffer_restored\' => ob_get_level() === $before], JSON_THROW_ON_ERROR);';
    file_put_contents($childFile, $source);
    $command = [PHP_BINARY];
    if (($ini = php_ini_loaded_file()) !== false) { $command[] = '-c'; $command[] = $ini; }
    $command[] = '-d'; $command[] = 'log_errors=1';
    $command[] = '-d'; $command[] = 'error_reporting=32767';
    $command[] = $childFile;
    $process = proc_open($command, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, dirname($copy));
    if (!is_resource($process)) { throw new RuntimeException('Entry diagnostic child process unavailable.'); }
    fclose($pipes[0]);
    $stdout = stream_get_contents($pipes[1]); fclose($pipes[1]);
    $stderr = stream_get_contents($pipes[2]); fclose($pipes[2]);
    $exit = proc_close($process);
    return ['exit' => $exit, 'stdout' => $stdout, 'stderr' => $stderr];
};
try {
    foreach (['active' => true, 'disabled' => false] as $variant => $diagnosticsEnabled) {
        $sourcePath = __DIR__ . '/fixtures/entry-diagnostics-' . $variant . '.php';
        $source = file_get_contents($sourcePath);
        $copy = $private . DIRECTORY_SEPARATOR . 'entry-' . $variant . '.php';
        $replaced = str_replace($originalBootstrap, var_export($fakeBootstrap, true), $source, $replacementCount);
        entryDiagnosticSame(1, $replacementCount, 'only fixed bootstrap literal is replaced in ' . $variant);
        entryDiagnosticSame($source, str_replace(var_export($fakeBootstrap, true), $originalBootstrap, $replaced), 'offline entry copy preserves all other source text');
        file_put_contents($copy, $replaced);

        foreach ($stages as $index => $stage) {
            $child = $runCopy($copy, $stage);
            entryDiagnosticSame(0, $child['exit'], 'caught stage failure exits normally');
            entryDiagnosticSame('', $child['stderr'], 'caught stage failure writes no stderr');
            entryDiagnosticCheck(!str_contains($child['stdout'] . $child['stderr'], $fictionalSecret), 'fictional exception secret never reaches either output stream');
            $result = json_decode($child['stdout'], true, 16, JSON_THROW_ON_ERROR);
            entryDiagnosticSame(503, $result['status'], 'entry failure returns service unavailable');
            entryDiagnosticSame(array_slice($stages, 0, $index + 1), $result['trace'], 'entry stops at the exact failing stage');
            entryDiagnosticSame(true, $result['buffer_restored'], 'entry leaves caller output buffer depth unchanged');
            $expected = ['error' => 'administrator_validation_unavailable'];
            if ($diagnosticsEnabled) { $expected['diagnostic'] = $stage . '_unavailable'; }
            entryDiagnosticSame(json_encode($expected, JSON_THROW_ON_ERROR), $result['stdout'], 'entry emits only fixed enabled or disabled failure shape');
            foreach ([$private, '/home/', 'RuntimeException', 'Stack trace', 'FICTIONAL_TEST_EXCEPTION'] as $privateText) {
                entryDiagnosticCheck(!str_contains($result['stdout'], $privateText), 'entry response contains no path, exception, trace, or secret text');
            }
        }

        $success = $runCopy($copy, 'none');
        entryDiagnosticSame(0, $success['exit'], 'successful offline entry exits normally');
        entryDiagnosticSame('', $success['stderr'], 'successful offline entry writes no stderr');
        $result = json_decode($success['stdout'], true, 16, JSON_THROW_ON_ERROR);
        entryDiagnosticSame(200, $result['status'], 'successful offline delivery preserves status');
        entryDiagnosticSame('{"type":1}', $result['stdout'], 'successful offline delivery remains unchanged');
        entryDiagnosticSame($stages, $result['trace'], 'successful entry reaches all five stages in order');

        // A missing fictional bootstrap is also classified before any stub can run.
        $missingCopy = $private . DIRECTORY_SEPARATOR . 'entry-' . $variant . '-missing.php';
        $missingBootstrap = $private . DIRECTORY_SEPARATOR . 'fictional-missing-bootstrap.php';
        file_put_contents($missingCopy, str_replace($originalBootstrap, var_export($missingBootstrap, true), $source));
        $missing = $runCopy($missingCopy, 'none');
        entryDiagnosticSame(0, $missing['exit'], 'missing fictional bootstrap is caught');
        entryDiagnosticSame('', $missing['stderr'], 'missing fictional bootstrap leaks no stderr');
        $result = json_decode($missing['stdout'], true, 16, JSON_THROW_ON_ERROR);
        $expected = ['error' => 'administrator_validation_unavailable'];
        if ($diagnosticsEnabled) { $expected['diagnostic'] = 'entry_bootstrap_unavailable'; }
        entryDiagnosticSame(503, $result['status'], 'missing fictional bootstrap returns service unavailable');
        entryDiagnosticSame(json_encode($expected, JSON_THROW_ON_ERROR), $result['stdout'], 'missing bootstrap keeps the fixed error shape');
        entryDiagnosticSame([], $result['trace'], 'missing bootstrap never constructs any class');
    }
    echo "Entry diagnostics: {$entryAssertions} assertions passed (offline copied entries only).\n";
} finally {
    foreach (glob($private . DIRECTORY_SEPARATOR . '*') as $ownedFile) {
        if (dirname($ownedFile) === $private && is_file($ownedFile)) { @unlink($ownedFile); }
    }
    @rmdir($private);
}
