<?php
declare(strict_types=1);

// Fixed private CLI task. Never deploy this file under public_html.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
ini_set('display_errors', '0');
umask(0077);

use RadioStamp\Http\AdminValidationRuntime;
use RadioStamp\Http\PrivateConfig;

try {
    if (($argc ?? 0) !== 1) { throw new RuntimeException(); }
    require dirname(__DIR__) . '/bootstrap.php';
    $config = PrivateConfig::load(
        '/home/hosting-user/hosting-user.example.invalid/radio-stamp-private/config/admin.php',
        '/home/hosting-user/hosting-user.example.invalid/public_html',
    );
    if (!PrivateConfig::validationScope($config) || ($config['command_registration_enabled'] ?? false) !== true) {
        echo "{\"ok\":false,\"code\":\"command_registration_disabled\"}\n";
        exit(2);
    }
    $result = (new AdminValidationRuntime())->registerCommands($config);
    $ok = ($result['ok'] ?? false) === true;
    // No settings, credentials, response bodies, identifiers, or exception text.
    $allowedCodes = ['command_registration_disabled', 'bot_check_read_failed', 'bot_check_identity_failed',
        'bot_check_permissions_unknown', 'bot_check_permissions_failed', 'validation_commands_ready', 'validation_commands_unavailable'];
    $code = in_array($result['code'] ?? null, $allowedCodes, true) ? $result['code'] : 'validation_setup_stopped';
    $safe = ['ok' => $ok, 'code' => $code];
    foreach (['bot_identity', 'application_identity', 'guild_membership', 'guide_permissions',
        'records_permissions', 'voice_permissions', 'restricted_permissions'] as $key) {
        if (is_bool($result['checks'][$key] ?? null)) { $safe['checks'][$key] = $result['checks'][$key]; }
    }
    echo json_encode($safe, JSON_THROW_ON_ERROR) . "\n";
    exit($ok ? 0 : 1);
} catch (Throwable) {
    echo "{\"ok\":false,\"code\":\"validation_setup_unavailable\"}\n";
    exit(1);
}
