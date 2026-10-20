<?php
declare(strict_types=1);
require_once __DIR__.'/artifact_directory.php';


$files = ['core.php', 'concurrency.php', 'http.php', 'cards.php', 'voice_safety.php', 'core_admin_validation.php', 'runtime_check.php', 'rest.php', 'command_response_inspector.php', 'admin_http.php', 'connection_receipt.php', 'config_diagnostics.php', 'config_entry_diagnostics.php', 'one_join.php', 'presence_diagnostics.php', 'one_join_window.php', 'participation_policy.php', 'status.php', 'achievements.php', 'post_exercise.php', 'operations_adapter.php', 'operations_recovery.php', 'gateway_connection.php', 'operations_runtime.php'];
$failures = 0;
$files[] = 'production_parity.php';
$files[] = 'operations_diagnostics.php';
$files[] = 'ack_recovery.php';
$files[] = 'unsent_recovery.php';
$files[] = 'schedule_safety.php';
$files[] = 'card_capacity.php';
$files[] = 'gateway_guard_recovery.php';
$files[] = 'card_stamps.php';
$files[] = 'five_slot_policy.php';
$files[] = 'award_notifications.php';
$files[] = 'memorial_artwork.php';
$files[] = 'card_download.php';
foreach ($files as $name) {
    $path = __DIR__ . '/' . $name;
    if (!is_file($path)) { fwrite(STDERR, "MISSING: $name\n"); $failures++; continue; }
    $command = [PHP_BINARY];
    if (($ini = php_ini_loaded_file()) !== false) { $command[] = '-c'; $command[] = $ini; }
    $command[] = $path;
    $process = proc_open($command, [0 => STDIN, 1 => STDOUT, 2 => STDERR], $pipes, dirname(__DIR__));
    if (!is_resource($process) || proc_close($process) !== 0) { $failures++; }
}
exit($failures === 0 ? 0 : 1);
