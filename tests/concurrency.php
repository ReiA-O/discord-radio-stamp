<?php
declare(strict_types=1);
require_once __DIR__.'/artifact_directory.php';
require dirname(__DIR__) . '/bootstrap.php';

use RadioStamp\Core\Database;
use RadioStamp\Core\FrozenClock;
use RadioStamp\Core\RadioService;
use RadioStamp\Discord\MockGateway;

function rsConcurrencyService(string $database): array
{
    $db = new Database($database);
    $clock = new FrozenClock('2026-10-17 07:00:00+09:00');
    $mock = new MockGateway($clock);
    $service = new RadioService($db, $mock, $clock, ['guild_id' => 'mock-guild', 'voice_channel_id' => 'mock-exercise', 'text_channel_id' => 'mock-guide', 'records_channel_id' => 'mock-records', 'destination_channel_id' => null]);
    return [$db, $clock, $mock, $service];
}

if (($argv[1] ?? '') === '--child') {
    [$db, $clock, $mock, $service] = rsConcurrencyService($argv[2]);
    $operation = $argv[3]; $index = $argv[4];
    $user = $operation === 'unique' ? 'mock-person-' . $index : 'mock-person';
    $mock->setVoiceState($user, 'mock-exercise');
    $codes = [];
    for ($i = 0; $i < ($operation === 'start' ? 1 : 10); $i++) {
        $id = $operation . '-' . $index . '-' . $i;
        $result = $operation === 'start' ? $service->start('2026-10-17_0700', $user, $id) : $service->attend('2026-10-17_0700', $user, $id);
        $codes[] = $result['code'];
    }
    echo json_encode($codes, JSON_THROW_ON_ERROR);
    exit(0);
}

$rsConcurrencyChecks = 0;
function rsConcurrencyAssert(bool $condition, string $message): void
{
    global $rsConcurrencyChecks;
    $rsConcurrencyChecks++;
    if (!$condition) { throw new RuntimeException('Concurrency assertion failed: ' . $message); }
}
function rsConcurrencyRun(string $path, string $operation, int $workers): array
{
    $processes = [];
    for ($i = 0; $i < $workers; $i++) {
        $command = [PHP_BINARY];
        if (php_ini_loaded_file() !== false) { $command[] = '-c'; $command[] = php_ini_loaded_file(); }
        array_push($command, __FILE__, '--child', $path, $operation, (string)$i);
        $pipes = [];
        $process = proc_open($command, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null, null, ['bypass_shell' => true]);
        if (!is_resource($process)) { throw new RuntimeException('Cannot launch mock concurrency test.'); }
        fclose($pipes[0]); $processes[] = [$process, $pipes];
    }
    $codes = [];
    foreach ($processes as [$process, $pipes]) {
        $output = stream_get_contents($pipes[1]); $error = stream_get_contents($pipes[2]);
        fclose($pipes[1]); fclose($pipes[2]);
        $exit = proc_close($process);
        rsConcurrencyAssert($exit === 0, 'parallel mock worker succeeded: ' . $error);
        array_push($codes, ...json_decode($output, true, 32, JSON_THROW_ON_ERROR));
    }
    return array_count_values($codes);
}

$dir = sys_get_temp_dir() . '/radio-stamp-concurrency-' . bin2hex(random_bytes(8));
mkdir($dir, 0700);
$path = $dir . '/application.sqlite';
try {
    [$db, $clock, $mock, $service] = rsConcurrencyService($path);
    $service->planSessions(new DateTimeImmutable('2026-10-17 00:00:00+09:00'), new DateTimeImmutable('2026-10-17 23:59:59+09:00'));
    $startCodes = rsConcurrencyRun($path, 'start', 8);
    rsConcurrencyAssert(($startCodes['started'] ?? 0) === 1, 'exactly one concurrent start');
    rsConcurrencyAssert(($startCodes['already_started'] ?? 0) === 7, 'seven duplicate starts rejected');
    $sameUser = rsConcurrencyRun($path, 'same', 8);
    rsConcurrencyAssert(($sameUser['attended'] ?? 0) === 1, 'same-user eighty concurrent presses count once');
    rsConcurrencyAssert(($sameUser['already_attended'] ?? 0) === 79, 'other same-session presses are duplicates');
    $unique = rsConcurrencyRun($path, 'unique', 8);
    rsConcurrencyAssert(($unique['attended'] ?? 0) === 8, 'eight distinct users can all register');
    rsConcurrencyAssert(($unique['already_attended'] ?? 0) === 72, 'each distinct user still counts only once');
    rsConcurrencyAssert((int)$db->pdo()->query('SELECT COUNT(*) FROM attendance')->fetchColumn() === 9, 'no lost or duplicate attendance');
    rsConcurrencyAssert((int)$db->pdo()->query('SELECT SUM(total_count) FROM participants')->fetchColumn() === 9, 'aggregate totals match durable records');
    foreach ($db->pdo()->query('SELECT user_id FROM participants')->fetchAll(PDO::FETCH_COLUMN) as $participantId) {
        rsConcurrencyAssert($service->statusData($participantId)['total_days'] === 1, 'concurrent duplicate presses keep one JST participation day per ID');
    }
    rsConcurrencyAssert((int)$db->pdo()->query('SELECT COUNT(*) FROM core_interactions')->fetchColumn() === 168, 'all interaction decisions persisted uniquely');
    rsConcurrencyAssert($db->pdo()->query('PRAGMA integrity_check')->fetchColumn() === 'ok', 'SQLite file integrity');
    echo 'concurrency: ' . $rsConcurrencyChecks . " assertions passed; 8 processes, 168 requests, 9 attendance records\n";
} finally {
    unset($db, $service); gc_collect_cycles();
    foreach (glob($dir . '/*') ?: [] as $file) { if (is_file($file)) { @unlink($file); } }
    @rmdir($dir);
}
