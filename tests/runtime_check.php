<?php
declare(strict_types=1);
require_once __DIR__.'/artifact_directory.php';
require_once dirname(__DIR__) . '/bootstrap.php';

use RadioStamp\Http\PrivateRuntimeCheck;

$checks = 0;
$assert = static function (bool $condition, string $description) use (&$checks): void {
    if (!$condition) { throw new RuntimeException('Runtime diagnostic test failed: ' . $description); }
    ++$checks;
};
$featureKeys = ['curl', 'gd', 'sodium', 'pdo_sqlite', 'sqlite_online_backup', 'mbstring', 'fastcgi_finish_request', 'symlink'];
$checkKeys = ['private_storage', 'private_write', 'public_candidate_guard', 'dangling_symlink_guard', 'scratch_permissions', 'cleanup'];
$enums = ['pass', 'skip', 'fail', 'private_root_unavailable', 'public_root_unavailable',
    'private_location_not_accepted', 'private_var_unavailable', 'scratch_creation_unavailable',
    'scratch_permissions_unavailable', 'windows_permissions_not_measured', 'private_write_unavailable',
    'public_candidate_not_rejected', 'dangling_fixture_not_established', 'windows_symlink_not_measured',
    'symlink_function_unavailable', 'symlink_creation_unavailable', 'dangling_symlink_not_rejected',
    'own_fixture_cleanup_incomplete'];
$validate = static function (array $report) use ($assert, $featureKeys, $checkKeys, $enums): void {
    $assert(array_keys($report) === ['status', 'features', 'checks', 'reasons'], 'fixed report fields');
    $assert(in_array($report['status'], ['pass', 'skip', 'fail'], true), 'fixed status enum');
    $assert(array_keys($report['features']) === $featureKeys, 'fixed feature fields');
    foreach ($report['features'] as $feature) { $assert(is_bool($feature), 'feature values are booleans'); }
    $assert(array_keys($report['checks']) === $checkKeys, 'fixed check fields');
    foreach ($report['checks'] as $value) { $assert(in_array($value, ['pass', 'skip', 'fail'], true), 'check values are fixed enums'); }
    $assert(array_is_list($report['reasons']), 'reasons are a list');
    foreach ($report['reasons'] as $reason) { $assert(in_array($reason, $enums, true), 'reason is a fixed enum'); }
};

$base = sys_get_temp_dir() . '/radio-runtime-fixture-' . bin2hex(random_bytes(8));
$private = $base . '/synthetic-private';
$public = $base . '/public_html';
$createdDirectories = [];
$createdFiles = [];
$createdLinks = [];
try {
    foreach ([$base, $private, $public] as $directory) {
        if (!mkdir($directory, 0700)) { throw new RuntimeException('Cannot create synthetic runtime fixture'); }
        $createdDirectories[] = $directory;
    }
    $features = PrivateRuntimeCheck::features();
    $assert(array_keys($features) === $featureKeys, 'features-only has fixed fields');
    foreach ($features as $feature) { $assert(is_bool($feature), 'features-only is bool'); }
    $assert(scandir($private) === ['.', '..'], 'features-only wrote no private fixture');
    $assert($features['fastcgi_finish_request'] === function_exists('fastcgi_finish_request'), 'FastCGI presence measured only');
    $assert($features['sqlite_online_backup'] === (extension_loaded('sqlite3') && class_exists(SQLite3::class, false) && method_exists(SQLite3::class, 'backup')), 'native online backup API measured separately from PDO SQLite');

    ob_start();
    $report = PrivateRuntimeCheck::run($private, $public);
    $assert(ob_get_clean() === '', 'successful probe emits no output');
    $validate($report);
    $assert($report['features'] === $features, 'full probe retains pure capability flags');
    foreach (['private_storage', 'private_write', 'public_candidate_guard', 'cleanup'] as $name) {
        $assert($report['checks'][$name] === 'pass', 'required local guard passed');
    }
    if (PHP_OS_FAMILY === 'Windows') {
        $assert($report['status'] === 'skip', 'Windows reports incomplete Linux measurements');
        $assert($report['checks']['dangling_symlink_guard'] === 'skip', 'Windows symlink skipped');
        $assert(in_array('windows_symlink_not_measured', $report['reasons'], true), 'Windows fixed symlink reason');
        $assert($report['checks']['scratch_permissions'] === 'skip', 'Windows POSIX mode skipped');
    } else {
        $assert($report['checks']['scratch_permissions'] === 'pass', 'POSIX scratch is 0700');
        if ($report['checks']['dangling_symlink_guard'] === 'pass') {
            $assert($report['status'] === 'pass', 'Linux successful dangling-link guard is pass');
        } else {
            $assert($report['status'] === 'skip', 'unavailable Linux symlink reports skip');
            $assert(count(array_intersect($report['reasons'], ['symlink_function_unavailable', 'symlink_creation_unavailable'])) === 1, 'fixed Linux symlink skip reason');
        }
    }
    $assert(scandir($private) === ['.', '..'], 'own var/scratch removed nonrecursively');
    $assert(scandir($public) === ['.', '..'], 'nothing created in public_html');

    // All existing files below are fictional and owned exclusively by this test.
    $var = $private . '/var';
    if (!mkdir($var, 0700)) { throw new RuntimeException('Cannot create synthetic var'); }
    $createdDirectories[] = $var;
    $sentinel = $var . '/existing.sqlite';
    $config = $private . '/settings.php';
    $fictionalDb = 'FICTIONAL_DATABASE_CONTENT_NEVER_A_REAL_DB';
    $fictionalConfig = '<?php throw new RuntimeException("FICTIONAL_CONFIG_MUST_NOT_EXECUTE");';
    file_put_contents($sentinel, $fictionalDb); $createdFiles[] = $sentinel;
    file_put_contents($config, $fictionalConfig); $createdFiles[] = $config;
    $again = PrivateRuntimeCheck::run($private, $public);
    $validate($again);
    $assert($again === $report, 'repeat report is deterministic and never executes fictional settings');
    $assert($again['checks']['cleanup'] === 'pass', 'repeat diagnostic cleans its own files');
    $assert(file_get_contents($sentinel) === $fictionalDb, 'fictional existing DB unchanged');
    $assert(file_get_contents($config) === $fictionalConfig, 'fictional config not executed or changed');
    $assert(scandir($var) === ['.', '..', 'existing.sqlite'], 'existing var retained with only prior contents');
    $encoded = json_encode($again, JSON_THROW_ON_ERROR);
    foreach ([$base, 'FICTIONAL_DATABASE', 'FICTIONAL_CONFIG', 'settings.php', 'existing.sqlite', '.runtime-check-', 'C:/', 'C:\\', '/tmp/'] as $privateValue) {
        $assert(!str_contains($encoded, $privateValue), 'report exposes no paths, values, or names');
    }

    foreach ([[$public, $public], [$public . '/not-created', $public], [$private, $base . '/missing-public'], ['relative-private-root', $public], [$config, $public], [$private, 'relative-public-root']] as [$badPrivate, $badPublic]) {
        ob_start();
        $bad = PrivateRuntimeCheck::run($badPrivate, $badPublic);
        $assert(ob_get_clean() === '', 'rejected root emits no warning or exception text');
        $validate($bad);
        $assert($bad['status'] === 'fail' && $bad['checks']['private_storage'] === 'fail', 'unsafe or missing roots fail closed');
        $assert($bad['checks']['cleanup'] === 'pass', 'rejected root leaves no owned fixture');
    }
    $assert(scandir($public) === ['.', '..'], 'rejected public storage wrote nothing');
    $nested = $public . '/synthetic-private';
    if (!mkdir($nested, 0700)) { throw new RuntimeException('Cannot create nested synthetic private'); }
    $createdDirectories[] = $nested;
    $bad = PrivateRuntimeCheck::run($nested, $public);
    $validate($bad);
    $assert($bad['status'] === 'fail' && !file_exists($nested . '/var'), 'private root nested in public_html refused before writing');

    // Existing var as a regular file must be preserved and refused.
    $otherPrivate = $base . '/file-var-private';
    if (!mkdir($otherPrivate, 0700)) { throw new RuntimeException('Cannot create file-var synthetic root'); }
    $createdDirectories[] = $otherPrivate;
    $fileVar = $otherPrivate . '/var';
    file_put_contents($fileVar, 'FICTIONAL_VAR_FILE'); $createdFiles[] = $fileVar;
    $bad = PrivateRuntimeCheck::run($otherPrivate, $public);
    $validate($bad);
    $assert($bad['status'] === 'fail' && file_get_contents($fileVar) === 'FICTIONAL_VAR_FILE', 'existing var file preserved');

    // Linux-only fixture: no elevated symlink permission is requested on Windows.
    if (PHP_OS_FAMILY !== 'Windows' && function_exists('symlink')) {
        $linkPrivate = $base . '/symlink-var-private';
        if (!mkdir($linkPrivate, 0700)) { throw new RuntimeException('Cannot create symlink synthetic root'); }
        $createdDirectories[] = $linkPrivate;
        $linkVar = $linkPrivate . '/var';
        if (@symlink($var, $linkVar)) {
            $createdLinks[] = $linkVar;
            $bad = PrivateRuntimeCheck::run($linkPrivate, $public);
            $validate($bad);
            $assert($bad['status'] === 'fail' && is_link($linkVar), 'preexisting symlink var refused without removing it');
            $assert(file_get_contents($sentinel) === $fictionalDb, 'symlink target fictional DB unchanged');
        }
    }
    echo 'Runtime check: ' . $checks . " checks passed.\n";
} finally {
    foreach (array_reverse($createdLinks) as $link) { if (!unlink($link)) { throw new RuntimeException('Own synthetic link cleanup failed'); } }
    foreach (array_reverse($createdFiles) as $file) { if (!unlink($file)) { throw new RuntimeException('Own synthetic file cleanup failed'); } }
    foreach (array_reverse($createdDirectories) as $directory) { if (!rmdir($directory)) { throw new RuntimeException('Own synthetic directory cleanup failed'); } }
}
