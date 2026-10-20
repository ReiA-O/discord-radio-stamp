<?php
declare(strict_types=1);

// Temporary fixed classifications on the existing endpoint only.
// No request field can select code, configuration, path, or operation.
ini_set('display_errors', '0');
umask(0077);
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');
header('X-Robots-Tag: noindex, nofollow');
$fixedDiagnostics = false;
$stage = 'entry_bootstrap';

try {
    $bootstrap = '/home/hosting-user/hosting-user.example.invalid/radio-stamp-private/releases/connect-20261017/bootstrap.php';
    if (!is_file($bootstrap)) { throw new RuntimeException(); }
    require $bootstrap;
    $publicRoot = '/home/hosting-user/hosting-user.example.invalid/public_html';
    $configPath = '/home/hosting-user/hosting-user.example.invalid/radio-stamp-private/config/admin.php';
    $body = file_get_contents('php://input', false, null, 0, 65537);
    $stage = 'entry_driver';
    $driver = new RadioStamp\Http\FastCgiPostResponseDriver();
    $stage = 'entry_endpoint_constructor';
    $endpoint = new RadioStamp\Http\PublicEndpoint($publicRoot, $driver);
    $stage = 'entry_prepare';
    $delivery = $endpoint->prepare($_SERVER, $body === false ? '' : $body, $configPath);
    $stage = 'entry_deliver';
    $delivery->deliver($driver);
} catch (Throwable) {
    http_response_code(503);
    header('Content-Type: application/json; charset=utf-8');
    $result = ['error' => 'administrator_validation_unavailable'];
    if ($fixedDiagnostics) {
        $result['diagnostic'] = match ($stage) {
            'entry_bootstrap' => 'entry_bootstrap_unavailable',
            'entry_driver' => 'entry_driver_unavailable',
            'entry_endpoint_constructor' => 'entry_endpoint_constructor_unavailable',
            'entry_prepare' => 'entry_prepare_unavailable',
            'entry_deliver' => 'entry_deliver_unavailable',
            default => 'entry_unavailable',
        };
    }
    echo json_encode($result, JSON_THROW_ON_ERROR);
}
