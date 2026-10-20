<?php
declare(strict_types=1);
namespace RadioStamp\Http;

use RadioStamp\Cards\CardRenderer;
use RadioStamp\Core\Database;
use RadioStamp\Core\RadioService;
use RadioStamp\Core\SystemClock;
use RadioStamp\Discord\CurlHttpAdapter;
use RadioStamp\Discord\DiscordRestClient;
use RadioStamp\Discord\RestGateway;
use RadioStamp\Discord\RestStateStore;
use RadioStamp\Security\TokenCipher;

final class AdminValidationRuntime implements ValidationRuntime
{
    /** The factory is a code-only seam for offline transport tests. */
    public function __construct(private readonly ?\Closure $clientFactory = null) {}

    public function runInteraction(array $config, string $interactionId, string $privateRoot, string $publicRoot): array
    {
        if (!PrivateConfig::ready($config) || !PrivateConfig::validationScope($config) || !PrivateConfig::snowflake($interactionId)) { return ['ok' => false, 'code' => 'validation_configuration_required']; }
        $previousMask = umask(0077);
        try {
        $db = new Database($config['private_db_path'], 200);
        $client = $this->client($config, ['state_store' => new RestStateStore($db->pdo())]);
        $clock = new SystemClock();
        $queue = new InteractionQueue($db->pdo(), new TokenCipher($config['token_encryption_key_hex']));
        $service = new RadioService($db, new RestGateway($client, $clock), $clock, $config);
        $check = static function () use ($client, $privateRoot, $publicRoot, $config, $clock): array {
            $result = (new BotValidationCheck($client))->run();
            $runtime = ($config['runtime_check_enabled'] ?? false) === true
                ? PrivateRuntimeCheck::run($privateRoot, $publicRoot)
                : ['status' => 'disabled', 'features' => PrivateRuntimeCheck::features(), 'checks' => []];
            foreach ($runtime['features'] as $key => $value) { $result['checks']['runtime_' . $key] = $value; }
            foreach ($runtime['checks'] as $key => $value) { $result['checks']['private_' . $key] = $value === 'pass'; }
            $result['runtime_status'] = $runtime['status'];
            if ($runtime['status'] === 'fail') { $result['ok'] = false; $result['code'] = 'private_runtime_check_failed'; }
            // Diagnostics can run from ordinary chat; never create duplicate session buttons there.
            // Session controls belong to the shared controls message in the target VC chat.
            $result['components'] = [];
            return $result;
        };
        $preflight = static function () use ($client): array {
            $identity = $client->botRead('self_bot');
            $ok = $identity->ok && ($identity->data['id'] ?? null) === PrivateConfig::APPLICATION_ID && ($identity->data['bot'] ?? false) === true;
            return ['ok' => $ok, 'code' => $ok ? 'validation_identity_verified' : 'validation_bot_identity_unverified'];
        };
        $worker = new InteractionWorker($queue, $service, static fn(): CardRenderer => new CardRenderer($config['font_candidates'] ?? []), new DiscordRestFollowupSink($client), $clock, $check, 'admin_validation', $preflight);
        return $worker->runOnce($interactionId) ?? ['ok' => false, 'code' => 'validation_no_pending_job'];
        } finally { umask($previousMask); }
    }

    public function registerCommands(array $config): array
    {
        if (!PrivateConfig::ready($config) || !PrivateConfig::validationScope($config) || ($config['command_registration_enabled'] ?? false) !== true) {
            return ['ok' => false, 'code' => 'command_registration_disabled'];
        }
        $previousMask = umask(0077);
        try {
        $db = new Database($config['private_db_path'], 200);
        $store = new RestStateStore($db->pdo());
        $client = $this->client($config, ['state_store' => $store]);
        $check = (new BotValidationCheck($client))->run();
        if (!$check['ok']) { return ['ok' => false, 'code' => $check['code'], 'checks' => $check['checks']]; }
        $registrar = $this->client($config, ['state_store' => $store, 'command_registration_enabled' => true, 'connection_ready' => true]);
        $result = $registrar->upsertValidationCommands();
        return ['ok' => $result->ok, 'code' => $result->ok ? 'validation_commands_ready' : 'validation_commands_unavailable'];
        } finally { umask($previousMask); }
    }

    /** Exactly one Bot identity GET, no callback token retention or private DB. */
    public function inlineCheck(array $config, float $deadline): array
    {
        $features = PrivateRuntimeCheck::features();
        $features['curl_async_dns'] = extension_loaded('curl') && defined('CURL_VERSION_ASYNCHDNS') && (((curl_version()['features'] ?? 0) & CURL_VERSION_ASYNCHDNS) !== 0);
        $checks = ['bot_identity' => false, ...$features];
        if (!PrivateConfig::signatureReady($config) || !PrivateConfig::validationScope($config) || !PrivateConfig::botConfigured($config)) {
            return ['ok' => false, 'code' => 'validation_configuration_required', 'checks' => $checks];
        }
        if (!$features['curl'] || !$features['curl_async_dns']) { return ['ok' => false, 'code' => 'transport_async_dns_required', 'checks' => $checks]; }
        if (hrtime(true) / 1e9 >= $deadline) { return ['ok' => false, 'code' => 'validation_deadline_exceeded', 'checks' => $checks]; }
        $client = $this->client($config, ['inline_self_bot_only' => true, 'timeout_ms' => 900, 'connect_timeout_ms' => 300, 'deadline_monotonic' => $deadline]);
        $result = $client->botRead('self_bot');
        if (hrtime(true) / 1e9 >= $deadline) { return ['ok' => false, 'code' => 'validation_deadline_exceeded', 'checks' => $checks]; }
        $checks['bot_identity'] = $result->ok && ($result->data['id'] ?? null) === PrivateConfig::APPLICATION_ID && ($result->data['bot'] ?? false) === true;
        $code = $checks['bot_identity'] ? 'bot_connection_verified' : match ($result->code) {
            'unauthorized' => 'bot_connection_unauthorized',
            'forbidden' => 'bot_connection_forbidden',
            'rate_limited' => 'bot_connection_rate_limited',
            'transport_timeout' => 'bot_connection_timeout',
            'operation_deadline' => 'validation_deadline_exceeded',
            'transport_async_dns_required' => 'transport_async_dns_required',
            'identity_mismatch' => 'bot_identity_mismatch',
            default => 'bot_connection_unverified',
        };
        return ['ok' => $checks['bot_identity'], 'code' => $code, 'checks' => $checks];
    }

    private function client(array $config, array $options): DiscordRestClient
    {
        $options['enabled'] = true;
        return $this->clientFactory === null
            ? new DiscordRestClient(new CurlHttpAdapter(), $config['application_id'], $config['bot_token'], $options)
            : ($this->clientFactory)($config, $options);
    }
}
