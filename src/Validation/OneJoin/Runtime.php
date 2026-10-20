<?php
declare(strict_types=1);
namespace RadioStamp\Validation\OneJoin;

use RadioStamp\Core\Clock;
use RadioStamp\Core\RadioService;
use RadioStamp\Cards\CardRenderer;
use RadioStamp\Discord\DiscordRestClient;
use RadioStamp\Discord\HttpAdapter;
use RadioStamp\Discord\PresenceDiagnostic;
use RadioStamp\Discord\RestGateway;
use RadioStamp\Discord\RestStateStore;
use RadioStamp\Http\BotValidationCheck;
use RadioStamp\Http\InteractionQueue;
use RadioStamp\Http\InteractionWorker;
use RadioStamp\Http\PrivateConfig;
use RadioStamp\Security\TokenCipher;

final class Runtime
{
    public function __construct(private readonly Store $store, private readonly HttpAdapter $http, private readonly Clock $clock, #[\SensitiveParameter] private readonly array $config) {}
    public function __debugInfo(): array { return ['scope' => Trial::NAME, 'credentials' => '[redacted]']; }
    public function run(string $id): void
    {
        if (!$this->store->start($id)) { return; }
        $op = $this->store->operation($id); $identity = false; $sink = null; $publisher = null;
        $code = 'runtime_failed'; $phase = 'failed';
        try {
            $http = new GuardedHttp($this->http, $this->store, $id, $this->clock);
            $client = new DiscordRestClient($http, PrivateConfig::APPLICATION_ID, $this->config['bot_token'], [
                'enabled' => true, 'state_store' => new RestStateStore($this->store->db->pdo()),
                'now' => fn(): float => (float)$this->clock->now()->getTimestamp(),
                'timeout_ms' => 3000, 'connect_timeout_ms' => 1000, 'deadline_monotonic' => hrtime(true) / 1e9 + 30,
            ]);
            $publisher = new Publisher($http, $this->store, $this->config['bot_token']);
            $preflight = static function () use ($client, &$identity): array {
                $result = $client->botRead('self_bot');
                $identity = $result->ok && ($result->data['id'] ?? null) === PrivateConfig::APPLICATION_ID && ($result->data['bot'] ?? false) === true;
                return ['ok' => $identity, 'code' => 'validation_bot_identity_unverified'];
            };
            $check = function () use ($client, $publisher, &$identity, $op): array {
                if ($op['step'] === 'cleanup') { return ['ok' => $publisher->cleanup($identity), 'code' => $this->store->state()['cleanup'] === 'deleted' || !$this->store->state()['post_attempted'] ? 'trial_cleanup_ready' : 'cleanup_unconfirmed']; }
                $checked = (new BotValidationCheck($client))->run();
                if (!$checked['ok']) { return $checked; }
                try { new CardRenderer($this->config['font_candidates']); }
                catch (\Throwable) { return ['ok' => false, 'code' => 'card_preflight_failed']; }
                $this->store->seed($this->clock);
                return ['ok' => $publisher->publish($identity), 'code' => $this->store->state()['message_id'] !== null ? 'trial_controls_ready' : 'controls_post_failed'];
            };
            $queue = new InteractionQueue($this->store->db->pdo(), new TokenCipher($this->config['token_encryption_key_hex']));
            $gateway = new RestGateway($client, $this->clock, fn(PresenceDiagnostic $diagnostic) => $this->store->recordPresence($id, $diagnostic));
            $service = new RadioService($this->store->db, $gateway, $this->clock, $this->config);
            $sink = new TrialSink($client, $this->store, $op['step']);
            $worker = new InteractionWorker($queue, $service, fn(): CardRenderer => new CardRenderer($this->config['font_candidates'] ?? []), $sink, $this->clock, $check, 'admin_validation', $preflight);
            $result = $worker->runOnce($id);
            $code = Trial::safeCode($result['code'] ?? null);
            $expected = ($result['state'] ?? null) === 'done' && $sink->sent && Trial::expected($op['step'], $code, $this->store->counts(), $sink->image);
            if (in_array($op['step'], ['inside', 'duplicate'], true) && !$this->store->presenceRecorded($id)) { $expected = false; }
            if ($op['step'] !== 'cleanup' && $this->clock->now()->getTimestamp() >= $this->store->state()['expires_at']) { $expected = false; $code = 'expired'; }
            if ($expected) { $phase = Trial::next($op['step']); }
            if (!$expected || $op['step'] === 'card') { $publisher->cleanup($identity); }
        } catch (\Throwable) {
            if ($publisher !== null) { try { $publisher->cleanup($identity); } catch (\Throwable) {} }
        }
        $this->store->finish($id, $phase, $code, $sink?->sent ?? false, $sink?->image ?? false);
    }
}
