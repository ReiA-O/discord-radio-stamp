<?php
declare(strict_types=1);
namespace RadioStamp\Validation\OneJoin;

use RadioStamp\Core\Clock;
use RadioStamp\Discord\CurlHttpAdapter;
use RadioStamp\Discord\HttpAdapter;
use RadioStamp\Http\PostResponseDriver;
use RadioStamp\Http\PrivateConfig as Scope;
use RadioStamp\Http\Response;
use RadioStamp\Security\SignatureVerifier;

final class Endpoint
{
    public function __construct(private readonly string $privateBase, private readonly string $publicRoot,
        private readonly array $activation, private readonly PostResponseDriver $driver, private readonly Clock $clock,
        private readonly ?HttpAdapter $http = null, private readonly ?\Closure $configLoader = null) {}
    public function __debugInfo(): array { return ['scope' => Trial::NAME, 'credentials' => '[redacted]']; }
    public function prepare(array $server, string $raw): Delivery
    {
        $started = hrtime(true) / 1e9;
        if (($server['REQUEST_METHOD'] ?? null) !== 'POST') { return self::error(405, 'method_not_allowed'); }
        if (strlen($raw) > 65536) { return self::error(413, 'request_too_large'); }
        if (!Trial::validActivation($this->activation) || $this->clock->now()->getTimestamp() < $this->activation['starts_at']) { return self::error(503, 'trial_disabled'); }
        if ($this->clock->now()->getTimestamp() >= Window::overallDeadline($this->activation)) { return self::error(503, 'trial_expired'); }
        $active = Trial::active($this->activation, $this->clock);
        if (!$active && !Store::exists($this->privateBase)) { return self::error(503, 'trial_expired'); }
        $mask = umask(0077);
        try {
            // The only credential read is the existing server-side loader. No value
            // is copied into a package, response, diagnostic, or new config file.
            $path = rtrim($this->privateBase, '/\\') . '/config/fresh-20261017-v1/admin.php';
            $config = $this->configLoader === null ? Scope::load($path, $this->publicRoot, false) : ($this->configLoader)();
            if (!is_array($config) || !Scope::ready($config) || !Scope::validationScope($config)) { return self::error(503, 'trial_configuration_unavailable'); }
            $stamp = $server['HTTP_X_SIGNATURE_TIMESTAMP'] ?? null; $signature = $server['HTTP_X_SIGNATURE_ED25519'] ?? null;
            if (!is_string($stamp) || !is_string($signature) || !(new SignatureVerifier($config['public_key_hex']))->verify($stamp, $signature, $raw, $this->clock->now()->getTimestamp())) { return self::error(401, 'invalid_signature'); }
            $data = json_decode($raw, true, 32, JSON_THROW_ON_ERROR);
            if (!is_array($data) || ($data['application_id'] ?? null) !== Scope::APPLICATION_ID) { return self::error(403, 'wrong_application'); }
            if (($data['type'] ?? null) === 1) { return new Delivery(new Response(200, ['type' => 1])); }
            if (($data['guild_id'] ?? null) !== Scope::GUILD_ID || ($data['member']['user']['id'] ?? null) !== Scope::OWNER_ID) { return self::error(403, 'owner_scope_required'); }
            if (!Scope::snowflake($data['id'] ?? null) || !is_string($data['token'] ?? null) || strlen($data['token']) < 1 || strlen($data['token']) > 2048) { return self::error(400, 'invalid_interaction'); }
            $input = $data['data'] ?? null; $kind = null;
            if (($data['type'] ?? null) === 2 && is_array($input) && ($input['type'] ?? null) === 1 && ($input['name'] ?? null) === 'radio-check' && ($input['id'] ?? null) === Trial::COMMAND_ID && ($input['options'] ?? []) === [] && ($data['channel_id'] ?? null) === Scope::GUIDE_ID) {
                $kind = 'check';
            } elseif (($data['type'] ?? null) === 3 && is_array($input) && ($input['component_type'] ?? null) === 2 && is_string($input['custom_id'] ?? null)) {
                if (($data['channel_id'] ?? null) !== Scope::VOICE_ID) { return new Delivery(Trial::privateReply('対象VCの付属チャットのテスト投稿から操作してください。')); }
                if (!$active) { return new Delivery(Trial::privateReply('テスト受付は終了しました。追加参加は記録しません。')); }
                $kind = str_starts_with($input['custom_id'] ?? '', 'radio:attend:') ? 'attend' : 'card';
            }
            if ($kind === null) { return self::error(400, 'unsupported_action'); }
            if (!$this->driver->available()) { return self::error(503, 'post_response_unavailable'); }
            $exists = Store::exists($this->privateBase);
            if (!$exists && ($kind !== 'check' || !$active)) { return self::error(503, 'trial_not_ready'); }
            if (!$exists && $this->clock->now()->getTimestamp() > $this->activation['expires_at'] - Window::VERIFY_SECONDS) { return self::error(503, 'trial_insufficient_time'); }
            $store = new Store($this->privateBase, $this->publicRoot, $this->activation, $this->clock, !$exists);
            $state = $store->state();
            if (!$active && ($kind !== 'check' || $state['check_count'] !== 1)) { return self::error(503, 'trial_expired'); }
            if ($kind !== 'check') {
                $expectedId = $kind === 'attend' ? 'radio:attend:' . $state['session'] : 'radio:card:' . $state['month'];
                if (($input['custom_id'] ?? null) !== $expectedId || $state['message_id'] === null || ($data['message']['id'] ?? null) !== $state['message_id'] ||
                    ($data['message']['author']['id'] ?? null) !== Scope::APPLICATION_ID || ($data['message']['author']['bot'] ?? null) !== true) { return self::error(403, 'wrong_test_message'); }
            }
            // Derive a new path, never open the source configuration's DB.
            $config['private_db_path'] = $store->root . DIRECTORY_SEPARATOR . 'fixture.sqlite';
            $config['runtime_check_enabled'] = false; $config['command_registration_enabled'] = false;
            $config['font_candidates'] = Trial::fonts();
            $response = $store->admit($config, $this->clock, $server, $raw, $data, $kind);
            return new Delivery($response, $store, new Runtime($store, $this->http ?? new CurlHttpAdapter(), $this->clock, $config), $started);
        } catch (\Throwable) { return self::error(503, 'trial_unavailable'); }
        finally { umask($mask); }
    }
    private static function error(int $status, string $code): Delivery { return new Delivery(new Response($status, ['error' => $code])); }
}
