<?php
declare(strict_types=1);
namespace RadioStamp\Http;

use RadioStamp\Core\Database;
use RadioStamp\Core\SystemClock;
use RadioStamp\Security\TokenCipher;

/** The entire bootstrap/ACK route is testable with a real SQLite write lock. */
final class LocalEndpoint
{
    public function __construct(private readonly string $publicRoot) {}

    public function handle(array $server, string $rawBody, ?string $configPath): Response
    {
        if (!in_array($server['REMOTE_ADDR'] ?? '', ['127.0.0.1', '::1'], true)) {
            return new Response(503, ['error' => 'local_administrator_validation_only']);
        }
        try {
            if ($configPath === null || $configPath === '') { throw new \RuntimeException('Unavailable'); }
            $config = PrivateConfig::load($configPath, $this->publicRoot);
            $db = new Database($config['private_db_path'], 200);
            $queue = new InteractionQueue($db->pdo(), new TokenCipher($config['token_encryption_key_hex']));
            $headers = [
                'x-signature-ed25519' => $server['HTTP_X_SIGNATURE_ED25519'] ?? '',
                'x-signature-timestamp' => $server['HTTP_X_SIGNATURE_TIMESTAMP'] ?? '',
            ];
            return (new InteractionController($config, $queue, new SystemClock()))->handle($server['REQUEST_METHOD'] ?? '', $headers, $rawBody);
        } catch (\Throwable) {
            return new Response(503, ['error' => 'administrator_mock_unavailable']);
        }
    }
}
