<?php
declare(strict_types=1);
namespace RadioStamp\Http;

use RadioStamp\Core\Database;
use RadioStamp\Core\SystemClock;
use RadioStamp\Security\TokenCipher;

/** A signed owner-only endpoint, kept disabled by its private configuration. */
final class PublicEndpoint
{
    // Command registration needs separate approval and a deliberate source change.
    private const ALLOW_PING_COMMAND_REGISTRATION = false;
    // One-off check: switch off after the explicitly approved connection attempt.
    private const ALLOW_CONNECTION_RECEIPT = false;
    // Temporary fixed classifications only; switch off after approved diagnosis.
    private const ALLOW_FIXED_DIAGNOSTICS = true;
    private readonly ValidationRuntime $runtime;
    private readonly ConnectionValidationReceipt $receipt;

    public function __construct(private readonly string $publicRoot, private readonly PostResponseDriver $driver, ?ValidationRuntime $runtime = null, ?ConnectionValidationReceipt $receipt = null)
    {
        $this->runtime = $runtime ?? new AdminValidationRuntime();
        $this->receipt = $receipt ?? new ConnectionValidationReceipt();
    }

    public function prepare(array $server, string $rawBody, ?string $configPath): EndpointDelivery
    {
        $previousMask = umask(0077);
        try {
            if ($configPath === null || $configPath === '') { throw new \RuntimeException('Unavailable', 1102); }
            $config = PrivateConfig::load($configPath, $this->publicRoot, false);
            if ($config['mode'] === 'mock' && !in_array($server['REMOTE_ADDR'] ?? '', ['127.0.0.1', '::1'], true)) {
                return new EndpointDelivery(new Response(503, ['error' => 'local_administrator_validation_only']));
            }
            $headers = [
                'x-signature-ed25519' => $server['HTTP_X_SIGNATURE_ED25519'] ?? '',
                'x-signature-timestamp' => $server['HTTP_X_SIGNATURE_TIMESTAMP'] ?? '',
            ];
            $queue = null;
            if (PrivateConfig::ready($config) && ($config['mode'] === 'mock' || $this->driver->available())) {
                $queue = static function () use ($config): InteractionQueue {
                    $db = new Database($config['private_db_path'], 200);
                    return new InteractionQueue($db->pdo(), new TokenCipher($config['token_encryption_key_hex']));
                };
            }
            $response = (new InteractionController($config, $queue, new SystemClock(), $this->driver->available()))->handle($server['REQUEST_METHOD'] ?? '', $headers, $rawBody);
            if ($response->status !== 200 || $config['mode'] !== 'admin_validation') { return new EndpointDelivery($response); }
            $privateRoot = dirname($configPath, 2);
            if ($response->interactionId !== null) {
                // The existing owner-only type 2/3 interaction path is unchanged.
                return new EndpointDelivery($response, fn(): array => $this->runtime->runInteraction($config, $response->interactionId, $privateRoot, $this->publicRoot));
            }
            $pingCallbacks = [];
            if (($response->body['type'] ?? null) === 1 && ($config['runtime_check_enabled'] ?? false) === true && $this->driver->available()) {
                // A feature receipt is not connection proof and must not delay PONG.
                $pingCallbacks[] = fn(): bool => EndpointCapabilities::save($privateRoot, $this->publicRoot);
            }
            if (self::ALLOW_CONNECTION_RECEIPT && ($response->body['type'] ?? null) === 1 &&
                $this->driver->available() && ConnectionValidationReceipt::enabled($config)) {
                // EndpointDelivery calls this only after successful response finish.
                // This new hook is type 1 only and can dispatch only its fixed GETs.
                $pingCallbacks[] = fn(): array => $this->receipt->run($config, $privateRoot, $this->publicRoot);
            }
            if (self::ALLOW_PING_COMMAND_REGISTRATION && ($response->body['type'] ?? null) === 1 && $this->driver->available() &&
                ($config['command_registration_enabled'] ?? false) === true && PrivateConfig::ready($config)) {
                $pingCallbacks[] = fn(): array => $this->runtime->registerCommands($config);
            }
            return new EndpointDelivery($response, $pingCallbacks === [] ? null : static function () use ($pingCallbacks): void {
                foreach ($pingCallbacks as $callback) { $callback(); }
            });
        } catch (\Throwable $failure) {
            $body = ['error' => 'administrator_validation_unavailable'];
            if (self::ALLOW_FIXED_DIAGNOSTICS) {
                // Never expose an exception message, class, stack, source, or value.
                $body['diagnostic'] = match ($failure instanceof \RuntimeException ? $failure->getCode() : 0) {
                    1101 => 'configuration_path_rejected',
                    1102 => 'configuration_file_unavailable',
                    1103 => 'configuration_output_guard_unavailable',
                    1104 => 'configuration_parse_failed',
                    1105 => 'configuration_require_failed',
                    1106 => 'configuration_output_detected',
                    1107 => 'configuration_bom_detected',
                    1108 => 'configuration_not_array',
                    1109 => 'configuration_scope_unavailable',
                    1110 => 'configuration_queue_unavailable',
                    1111 => 'configuration_storage_type_invalid',
                    1112 => 'configuration_storage_path_rejected',
                    default => 'diagnostic_unknown',
                };
            }
            return new EndpointDelivery(new Response(503, $body));
        } finally { umask($previousMask); }
    }
}
