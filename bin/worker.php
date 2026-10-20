<?php
declare(strict_types=1);
umask(0077);
require dirname(__DIR__) . '/bootstrap.php';

use RadioStamp\Cards\CardRenderer;
use RadioStamp\Core\Database;
use RadioStamp\Core\RadioService;
use RadioStamp\Core\SystemClock;
use RadioStamp\Discord\MockGateway;
use RadioStamp\Http\InteractionQueue;
use RadioStamp\Http\InteractionWorker;
use RadioStamp\Http\MockFollowupSink;
use RadioStamp\Http\PrivateConfig;
use RadioStamp\Security\TokenCipher;

if (PHP_SAPI !== 'cli') { http_response_code(503); exit; }
$options = getopt('', ['mock', 'config:', 'max:']);
if (!array_key_exists('mock', $options) || !is_string($options['config'] ?? null)) {
    fwrite(STDERR, "Usage: php bin/worker.php --mock --config=<private absolute PHP config> [--max=10]\n");
    exit(2);
}
try {
    $config = PrivateConfig::load($options['config'], dirname(__DIR__) . '/public');
    if (($config['mode'] ?? null) !== 'mock') { throw new RuntimeException('CLI worker is mock-only.'); }
    $maximum = isset($options['max']) && is_string($options['max']) && preg_match('/\A[1-9][0-9]?\z/D', $options['max']) ? (int) $options['max'] : 10;
    $clock = new SystemClock();
    $db = new Database($config['private_db_path']);
    $gateway = new MockGateway($clock); // Unknown presence stays unknown by default.
    $service = new RadioService($db, $gateway, $clock, $config);
    $tick = $service->tick();
    $queue = new InteractionQueue($db->pdo(), new TokenCipher($config['token_encryption_key_hex']));
    $sink = new MockFollowupSink();
    $worker = new InteractionWorker($queue, $service, new CardRenderer($config['font_candidates'] ?? []), $sink, $clock);
    $jobs = [];
    for ($i = 0; $i < $maximum; $i++) {
        $result = $worker->runOnce();
        if ($result === null) { break; }
        $jobs[] = $result;
    }
    echo json_encode(['mode' => 'mock_only', 'tick' => $tick, 'jobs' => $jobs, 'followups' => array_values($sink->records), 'gateway_actions' => $gateway->actionLog], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) . "\n";
} catch (Throwable) {
    fwrite(STDERR, "Administrator mock worker unavailable; private configuration or runtime needs attention.\n");
    exit(1);
}
