<?php
declare(strict_types=1);
require_once __DIR__.'/artifact_directory.php';
require dirname(__DIR__) . '/bootstrap.php';

use RadioStamp\Core\Database;
use RadioStamp\Core\FrozenClock;
use RadioStamp\Core\RadioService;
use RadioStamp\Discord\DiscordRestClient;
use RadioStamp\Discord\ButtonBuilder;
use RadioStamp\Discord\HttpAdapter;
use RadioStamp\Discord\HttpRequest;
use RadioStamp\Discord\HttpResponse;
use RadioStamp\Discord\MockGateway;
use RadioStamp\Http\AdminValidationRuntime;
use RadioStamp\Http\BotValidationCheck;
use RadioStamp\Http\EndpointCapabilities;
use RadioStamp\Http\InteractionController;
use RadioStamp\Http\InteractionQueue;
use RadioStamp\Http\InteractionWorker;
use RadioStamp\Http\MockFollowupSink;
use RadioStamp\Http\PostResponseDriver;
use RadioStamp\Http\PrivateConfig;
use RadioStamp\Http\PublicEndpoint;
use RadioStamp\Http\Response;
use RadioStamp\Http\ValidationRuntime;
use RadioStamp\Http\ValidationControls;
use RadioStamp\Security\TokenCipher;

$adminHttpAssertions = 0;
function adminHttpCheck(bool $condition, string $description): void
{
    global $adminHttpAssertions;
    if (!$condition) { throw new RuntimeException('Administrator HTTP test failed: ' . $description); }
    $adminHttpAssertions++;
}
function adminHttpSame(mixed $expected, mixed $actual, string $description): void
{
    adminHttpCheck($expected === $actual, $description);
}

final class AdminHttpEvents
{
    public array $items = [];
}
final class AdminHttpPostResponse implements PostResponseDriver
{
    public int $finishCalls = 0;
    public function __construct(private readonly AdminHttpEvents $events, public bool $supported = true, public bool $finished = true) {}
    public function available(): bool { return $this->supported; }
    public function finish(): bool
    {
        $this->finishCalls++;
        $this->events->items[] = 'finish';
        return $this->finished;
    }
}
/** Offline runtime records fixed operations only, never request or config secrets. */
final class AdminHttpRuntime implements ValidationRuntime
{
    public array $runs = [];
    public int $registrations = 0;
    public int $inlineCalls = 0;
    public float $lastBudget = 0.0;
    public function __construct(private readonly AdminHttpEvents $events) {}
    public function runInteraction(array $config, string $interactionId, string $privateRoot, string $publicRoot): array
    {
        $this->events->items[] = 'run:' . $interactionId;
        $this->runs[] = ['id' => $interactionId, 'mode' => $config['mode'], 'private_root' => $privateRoot, 'public_root' => $publicRoot];
        return ['ok' => true, 'code' => 'validation_ready'];
    }
    public function registerCommands(array $config): array
    {
        $this->events->items[] = 'register';
        $this->registrations++;
        return ['ok' => true, 'code' => 'validation_commands_ready'];
    }
    public function inlineCheck(array $config, float $deadline): array
    {
        $this->events->items[] = 'inline';
        $this->inlineCalls++;
        $this->lastBudget = $deadline - hrtime(true) / 1e9;
        return ['ok' => true, 'code' => 'validation_ready', 'checks' => ['bot_identity' => true, 'curl' => false,
            'gd' => 'not-a-boolean', 'arbitrary_private_check' => true], 'bot_token' => 'FICTIONAL_TEST_BOT_TOKEN_NOT_REAL'];
    }
}

/** Every request terminates here. No cURL adapter is ever constructed by tests. */
final class AdminHttpTransport implements HttpAdapter
{
    public array $requests = [];
    public function __construct(public array $responses = []) {}
    public function exchange(HttpRequest $request): HttpResponse
    {
        $path = (string)parse_url($request->url(), PHP_URL_PATH);
        $this->requests[] = ['method' => $request->method(), 'path' => str_replace('FICTIONAL_TEST_CALLBACK_TOKEN_NOT_REAL', '[callback]', $path),
            'bot_authorization' => count(array_filter($request->headers(), static fn(string $header): bool => str_starts_with($header, 'Authorization: Bot '))) === 1,
            'body' => $request->body(), 'timeout_ms' => $request->timeoutMs, 'connect_timeout_ms' => $request->connectTimeoutMs,
            'async_dns_required' => $request->asyncDnsRequired];
        $next = array_shift($this->responses);
        if ($next instanceof Throwable) { throw $next; }
        return $next instanceof HttpResponse ? $next : new HttpResponse(0, [], '', 'fictional_response_missing');
    }
}
function adminHttpJson(array $value): HttpResponse
{
    return new HttpResponse(200, [], json_encode($value, JSON_THROW_ON_ERROR));
}
/** Eight fixed reads: identities, membership, roles, and each approved channel. */
function adminHttpBotFixture(): array
{
    $role = '900000000000000090';
    $required = 1024 | 2048 | 32768 | 1048576;
    return [
        ['id' => PrivateConfig::APPLICATION_ID, 'bot' => true],
        ['id' => PrivateConfig::APPLICATION_ID, 'bot' => ['id' => PrivateConfig::APPLICATION_ID]],
        ['id' => PrivateConfig::GUILD_ID, 'owner_id' => PrivateConfig::OWNER_ID],
        ['user' => ['id' => PrivateConfig::APPLICATION_ID], 'roles' => [$role], 'pending' => false],
        [['id' => PrivateConfig::GUILD_ID, 'permissions' => '0', 'position' => 0], ['id' => $role, 'permissions' => (string)$required, 'position' => 1]],
        ['id' => PrivateConfig::GUIDE_ID, 'guild_id' => PrivateConfig::GUILD_ID, 'type' => 0, 'nsfw' => true,
            'permission_overwrites' => [['id' => PrivateConfig::GUILD_ID, 'type' => 0, 'deny' => '2048', 'allow' => '0'], ['id' => $role, 'type' => 0, 'deny' => '0', 'allow' => '2048']]],
        ['id' => PrivateConfig::RECORDS_ID, 'guild_id' => PrivateConfig::GUILD_ID, 'type' => 0, 'nsfw' => true,
            'permission_overwrites' => [['id' => $role, 'type' => 0, 'deny' => '32768', 'allow' => '0'], ['id' => PrivateConfig::APPLICATION_ID, 'type' => 1, 'deny' => '0', 'allow' => '32768']]],
        ['id' => PrivateConfig::VOICE_ID, 'guild_id' => PrivateConfig::GUILD_ID, 'type' => 2, 'nsfw' => true,
            'permission_overwrites' => [['id' => PrivateConfig::GUILD_ID, 'type' => 0, 'deny' => '1048576', 'allow' => '0'], ['id' => PrivateConfig::APPLICATION_ID, 'type' => 1, 'deny' => '0', 'allow' => '1048576']]],
    ];
}

// All keys and tokens are deliberately fictional and generated only for this test.
// Fixed Discord IDs below identify the permitted scope; no network adapter is used.
$fictionalSeed = hash('sha256', 'FICTIONAL_RADIO_ADMIN_HTTP_TEST_SIGNING_SEED_NOT_REAL', true);
$fictionalPair = sodium_crypto_sign_seed_keypair($fictionalSeed);
$fictionalSecretKey = sodium_crypto_sign_secretkey($fictionalPair);
$fictionalPublicKey = bin2hex(sodium_crypto_sign_publickey($fictionalPair));
$fictionalEncryptionKey = hash('sha256', 'FICTIONAL_RADIO_ADMIN_HTTP_ENCRYPTION_TEST_KEY_NOT_REAL');
$fictionalBotToken = 'FICTIONAL_TEST_BOT_TOKEN_NOT_REAL';
$fictionalCallbackToken = 'FICTIONAL_TEST_CALLBACK_TOKEN_NOT_REAL';
$private = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'radio-admin-http-' . bin2hex(random_bytes(8));
mkdir($private, 0700);
$dbPath = $private . DIRECTORY_SEPARATOR . 'admin-http.sqlite';
$clock = new FrozenClock('2026-10-17T06:50:00+09:00');
$now = $clock->now()->getTimestamp();
$config = [
    'mode' => 'admin_validation', 'admin_validation_enabled' => true,
    'application_id' => '800000000000001003', 'guild_id' => '800000000000001001',
    'admin_user_ids' => ['800000000000001021'], 'public_key_hex' => $fictionalPublicKey,
    'bot_token' => $fictionalBotToken, 'token_encryption_key_hex' => $fictionalEncryptionKey,
    'private_db_path' => $dbPath, 'text_channel_id' => '800000000000001005',
    'records_channel_id' => '800000000000001006', 'voice_channel_id' => '800000000000001007',
    'member_actions_enabled' => false, 'live_automation_enabled' => false, 'role_ids' => [],
    'destination_channel_id' => null, 'timezone' => 'Asia/Tokyo', 'max_roster_age_seconds' => 5,
];
$encode = static fn(array $value): string => json_encode($value, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
$headersFor = static function (string $body, ?int $timestamp = null) use ($fictionalSecretKey, $clock): array {
    $stamp = (string) ($timestamp ?? $clock->now()->getTimestamp());
    return ['X-Signature-Timestamp' => $stamp, 'X-Signature-Ed25519' => bin2hex(sodium_crypto_sign_detached($stamp . $body, $fictionalSecretKey))];
};
$payload = [
    'id' => '900000000000001001', 'application_id' => $config['application_id'], 'type' => 3,
    'guild_id' => $config['guild_id'], 'channel_id' => $config['voice_channel_id'],
    'token' => $fictionalCallbackToken,
    'member' => ['nick' => 'Fictional administrator', 'user' => ['id' => $config['admin_user_ids'][0], 'username' => 'fictional_admin']],
    'data' => ['component_type' => 2, 'custom_id' => 'radio:start:2026-10-17_0700'],
];
$sendTo = static function (InteractionController $controller, array $value) use ($encode, $headersFor): Response {
    $body = $encode($value);
    return $controller->handle('POST', $headersFor($body), $body);
};
$db = null;
$pdo = null;
$queue = null;
$controller = null;
$worker = null;
try {
    // All session controls use JST, regardless of the clock object's timezone.
    foreach ([
        ['2026-10-17T23:29:59+09:00', null, '2026-10'],
        ['2026-10-17T23:30:00+09:00', '2026-10-18_0000', '2026-10'],
        ['2026-10-18T00:29:59+09:00', '2026-10-18_0000', '2026-10'],
        ['2026-10-18T00:30:00+09:00', null, '2026-10'],
        ['2026-10-17T03:29:59+09:00', null, '2026-10'],
        ['2026-10-17T03:30:00+09:00', '2026-10-17_0400', '2026-10'],
        ['2026-10-17T04:29:59+09:00', '2026-10-17_0400', '2026-10'],
        ['2026-10-17T04:30:00+09:00', null, '2026-10'],
        ['2026-10-17T06:30:00+09:00', '2026-10-17_0700', '2026-10'],
        ['2026-10-17T07:29:59+09:00', '2026-10-17_0700', '2026-10'],
        ['2026-10-17T07:30:00+09:00', null, '2026-10'],
        ['2026-10-17T21:30:00+09:00', '2026-10-17_2200', '2026-10'],
        ['2026-10-17T22:29:59+09:00', '2026-10-17_2200', '2026-10'],
        ['2026-10-17T22:30:00+09:00', null, '2026-10'],
        ['2026-10-17T14:30:00+00:00', '2026-10-18_0000', '2026-10'],
        ['2026-10-31T23:29:59+09:00', null, '2026-10'],
        ['2026-10-31T23:30:00+09:00', '2026-11-01_0000', '2026-11'],
        ['2026-11-01T00:30:00+09:00', null, '2026-11'],
        ['2026-12-31T23:30:00+09:00', '2027-01-01_0000', '2027-01'],
    ] as [$instant, $activeSession, $month]) {
        $controls = (new ValidationControls(new FrozenClock($instant)))->forNow();
        $expected = $activeSession === null
            ? [['type' => 1, 'components' => [['type' => 2, 'style' => 2, 'label' => 'カード表示', 'custom_id' => 'radio:card:' . $month]]]]
            : ButtonBuilder::sessionControls($activeSession);
        adminHttpSame($expected, $controls, 'control window uses inclusive opening and exclusive closing at ' . $instant);
        adminHttpSame('radio:card:' . $month, $controls[0]['components'][count($controls[0]['components']) - 1]['custom_id'], 'control card follows the selected JST session month');
    }
    adminHttpCheck(PrivateConfig::ready($config), 'fixed owner validation configuration is ready');
    adminHttpCheck(!PrivateConfig::ready([]), 'empty configuration is default off');
    foreach ([false, null, 1, 'true'] as $enabled) {
        $disabled = $config; $disabled['admin_validation_enabled'] = $enabled;
        adminHttpCheck(!PrivateConfig::signatureReady($disabled) && !PrivateConfig::ready($disabled), 'validation requires explicit boolean enablement');
    }
    $missing = $config; unset($missing['admin_validation_enabled']);
    adminHttpCheck(!PrivateConfig::ready($missing), 'missing validation switch is off');
    foreach (['live', '', 'production', null] as $mode) {
        $disabled = $config; $disabled['mode'] = $mode;
        adminHttpCheck(!PrivateConfig::signatureReady($disabled) && !PrivateConfig::ready($disabled), 'only named mock and administrator scopes are accepted');
    }
    foreach (['application_id', 'guild_id', 'text_channel_id', 'records_channel_id', 'voice_channel_id'] as $field) {
        $changed = $config; $changed[$field] = '900000000000000099';
        adminHttpCheck(!PrivateConfig::ready($changed), 'fixed validation scope rejects changed ' . $field);
    }
    foreach ([[], ['900000000000000099'], [$config['admin_user_ids'][0], '900000000000000099']] as $admins) {
        $changed = $config; $changed['admin_user_ids'] = $admins;
        adminHttpCheck(!PrivateConfig::ready($changed), 'validation permits exactly the fixed owner');
    }
    foreach (['member_actions_enabled', 'live_automation_enabled', 'role_ids'] as $field) {
        $changed = $config; unset($changed[$field]);
        adminHttpCheck(!PrivateConfig::ready($changed), 'explicit safe setting required for ' . $field);
        $changed = $config; $changed[$field] = $field === 'role_ids' ? ['900000000000000099'] : true;
        adminHttpCheck(!PrivateConfig::ready($changed), 'unsafe setting rejected for ' . $field);
    }
    foreach ([null, 'UTC', 'America/New_York'] as $timezone) {
        $changed = $config; $changed['timezone'] = $timezone;
        adminHttpCheck(!PrivateConfig::ready($changed), 'validation requires the explicit Tokyo schedule timezone');
    }
    $mockConfig = $config; $mockConfig['mode'] = 'mock'; unset($mockConfig['bot_token']);
    $mockConfig['application_id'] = '900000000000000001';
    $mockConfig['guild_id'] = '900000000000000002';
    $mockConfig['admin_user_ids'] = ['900000000000000003'];
    adminHttpCheck(PrivateConfig::ready($mockConfig), 'fictional mock scope remains supported without a Bot token');
    foreach (['bot_token', 'token_encryption_key_hex'] as $field) {
        $missing = $config; $missing[$field] = null;
        adminHttpCheck(PrivateConfig::signatureReady($missing) && !PrivateConfig::ready($missing), 'signature validation is distinct from worker readiness');
    }

    // PING needs only signed application identity and the enabled fixed scope.
    // It must not need SQLite, callback encryption, or a Bot token.
    $pingConfig = $config; $pingConfig['bot_token'] = null; $pingConfig['token_encryption_key_hex'] = null;
    $pingController = new InteractionController($pingConfig, null, $clock);
    $ping = ['application_id' => $config['application_id'], 'type' => 1];
    adminHttpSame(['type' => 1], $sendTo($pingController, $ping)->body, 'signed PING succeeds without Bot or encryption keys');
    adminHttpCheck(!is_file($dbPath), 'signed PING does not initialize private database');
    $badPing = $ping; $badPing['application_id'] = '900000000000000099';
    adminHttpSame(403, $sendTo($pingController, $badPing)->status, 'signed PING still enforces application identity');
    $pingBody = $encode($ping);
    adminHttpSame(401, $pingController->handle('POST', [], $pingBody)->status, 'unsigned PING rejected');
    adminHttpSame(401, $pingController->handle('POST', $headersFor($pingBody), $pingBody . ' ')->status, 'raw PING bytes are signed');
    $disabled = $pingConfig; $disabled['admin_validation_enabled'] = false;
    adminHttpSame(503, $sendTo(new InteractionController($disabled, null, $clock), $ping)->status, 'disabled mode rejects signed PING');

    $db = new Database($dbPath, 200);
    $pdo = $db->pdo();
    $queue = new InteractionQueue($pdo, new TokenCipher($fictionalEncryptionKey));
    $controller = new InteractionController($config, $queue, $clock, true);
    $withoutFinish = new InteractionController($config, $queue, $clock, false);
    foreach (['radio:start:2026-10-17_0700', 'radio:card:2026-10'] as $customId) {
        $request = $payload; $request['data']['custom_id'] = $customId;
        $unavailable = $sendTo($withoutFinish, $request);
        adminHttpSame(503, $unavailable->status, 'deferred operation requires post-response support');
        adminHttpSame('post_response_unavailable', $unavailable->body['error'] ?? null, 'missing FastCGI support has a specific error');
    }
    adminHttpSame(0, (int)$pdo->query('SELECT COUNT(*) FROM interaction_jobs')->fetchColumn(), 'unsupported post-response mode does not enqueue or retain a token');

    // Every rejection below is signed. Scope failure must precede queue access.
    foreach (['application_id', 'guild_id', 'channel_id'] as $field) {
        $request = $payload; $request[$field] = '900000000000000099';
        adminHttpSame(403, $sendTo($controller, $request)->status, 'request enforces fixed ' . $field);
    }
    $request = $payload; unset($request['guild_id']);
    adminHttpSame(403, $sendTo($controller, $request)->status, 'DM interaction rejected');
    $request = $payload; $request['member']['user']['id'] = '900000000000000099';
    adminHttpSame(403, $sendTo($controller, $request)->status, 'other member rejected even with a valid signature');
    $request = $payload; $request['member']['user']['id'] = (int)$config['admin_user_ids'][0];
    adminHttpSame(403, $sendTo($controller, $request)->status, 'numeric owner ID cannot bypass strict string identity');
    adminHttpSame(0, (int)$pdo->query('SELECT COUNT(*) FROM interaction_jobs')->fetchColumn(), 'out-of-scope requests leave no queued work');

    foreach ([$config['text_channel_id'], $config['records_channel_id'], $config['voice_channel_id']] as $index => $channel) {
        $request = $payload; $request['id'] = (string)(900000000000001010 + $index); $request['channel_id'] = $channel;
        if ($channel !== $config['voice_channel_id']) { $request['data']['custom_id'] = 'radio:card:2026-10'; }
        $ack = $sendTo($controller, $request);
        adminHttpSame(['type' => 5, 'data' => ['flags' => 64]], $ack->body, 'cards remain private in each allowed channel and session controls use the VC chat');
        adminHttpSame($request['id'], $ack->interactionId, 'defer carries current interaction as private delivery metadata');
        adminHttpCheck(!array_key_exists('interaction_id', $ack->body), 'interaction metadata is absent from public response JSON');
    }
    $stored = $pdo->query('SELECT * FROM interaction_jobs ORDER BY interaction_id LIMIT 1')->fetch(PDO::FETCH_ASSOC);
    adminHttpSame('admin_validation', $stored['scope_mode'], 'durable work records validation mode');
    adminHttpCheck(!str_contains(json_encode($stored, JSON_THROW_ON_ERROR), $fictionalCallbackToken), 'callback token is absent from stored plaintext fields');
    foreach (glob($dbPath . '*') as $sqliteFile) {
        $contents = file_get_contents($sqliteFile);
        adminHttpCheck(!str_contains($contents, $fictionalCallbackToken), 'callback token absent from SQLite and WAL plaintext');
        adminHttpCheck(!str_contains($contents, $fictionalBotToken), 'Bot token is never persisted in queue storage');
    }
    $sameScopeMock = $config; $sameScopeMock['mode'] = 'mock';
    $request = $payload; $request['id'] = '900000000000001010';
    adminHttpSame(409, $sendTo(new InteractionController($sameScopeMock, $queue, $clock), $request)->status, 'same signed request ID cannot cross from validation into mock scope');

    // No FastCGI: no REST fallback may run before the initial response.
    $inlineController = new InteractionController($config, $queue, $clock, false);
    $checkRequest = $payload; $checkRequest['id'] = '900000000000001020'; $checkRequest['type'] = 2;
    $checkRequest['data'] = ['type' => 1, 'name' => 'radio-check'];
    $checkBody = $encode($checkRequest);
    adminHttpSame(401, $inlineController->handle('POST', [], $checkBody)->status, 'unsigned check rejected before inline callback');
    adminHttpSame(401, $inlineController->handle('POST', $headersFor($checkBody), $checkBody . ' ')->status, 'tampered check rejected before inline callback');
    adminHttpSame(401, $inlineController->handle('POST', $headersFor($checkBody, $now - 301), $checkBody)->status, 'expired check rejected before inline callback');
    foreach (['application_id', 'guild_id', 'channel_id'] as $field) {
        $request = $checkRequest; $request[$field] = '900000000000000099';
        adminHttpSame(403, $sendTo($inlineController, $request)->status, 'inline check enforces fixed ' . $field);
    }
    $request = $checkRequest; $request['member']['user']['id'] = '900000000000000099';
    adminHttpSame(403, $sendTo($inlineController, $request)->status, 'inline check rejects another member');
    $request = $checkRequest; $request['data']['options'] = [['name' => 'user', 'type' => 6, 'value' => '900000000000000099']];
    adminHttpSame(400, $sendTo($inlineController, $request)->status, 'check cannot accept a target user override');
    $inlineResponse = $sendTo($inlineController, $checkRequest);
    adminHttpSame(503, $inlineResponse->status, 'owner check requires a response finisher rather than inline REST');
    adminHttpSame('post_response_unavailable', $inlineResponse->body['error'] ?? null, 'missing finisher has a fixed rejection');
    adminHttpSame(null, $inlineResponse->interactionId, 'unsupported check does not schedule a deferred worker');
    adminHttpSame(3, (int)$pdo->query('SELECT COUNT(*) FROM interaction_jobs')->fetchColumn(), 'unsupported check stores no callback job or token');
    $publicInline = $encode($inlineResponse->body);
    foreach ([$fictionalBotToken, $fictionalCallbackToken, __FILE__, 'private_path', 'checks'] as $privateValue) {
        adminHttpCheck(!str_contains($publicInline, $privateValue), 'inline result omits private and detailed callback fields');
    }

    // Selecting a request ID must never drain earlier queued requests or another mode.
    $job = static fn(string $id, string $mode): array => ['interaction_id' => $id, 'action' => 'check', 'target' => 'fixed_scope',
        'user_id' => $config['admin_user_ids'][0], 'display_name' => 'Fictional administrator',
        'request_hash' => hash('sha256', $mode . ':' . $id), 'scope_mode' => $mode];
    $queue->enqueue($job('900000000000001030', 'mock'), $fictionalCallbackToken, $now);
    $queue->enqueue($job('900000000000001031', 'admin_validation'), $fictionalCallbackToken, $now);
    $queue->enqueue($job('900000000000001032', 'admin_validation'), $fictionalCallbackToken, $now);
    adminHttpSame(null, $queue->claim($now, 120, '900000000000001030', 'admin_validation'), 'claim refuses a current ID from another mode');
    adminHttpSame(null, $queue->claim($now, 120, '900000000000001099', 'admin_validation'), 'missing current ID never claims an older request');
    $claim = $queue->claim($now, 120, '900000000000001032', 'admin_validation');
    adminHttpSame('900000000000001032', $claim['interaction_id'] ?? null, 'claim selects the requested interaction only');
    $queue->finish($claim, 'test_complete');
    $gateway = new MockGateway($clock);
    $service = new RadioService($db, $gateway, $clock, $mockConfig);
    $sink = new MockFollowupSink();
    $checkCalls = 0;
    $workerCheck = static function () use (&$checkCalls): array { $checkCalls++; return ['ok' => true, 'code' => 'validation_ready']; };
    $noRenderer = static function (): never { throw new LogicException('Diagnostic worker must not load a renderer.'); };
    $worker = new InteractionWorker($queue, $service, $noRenderer, $sink, $clock, $workerCheck, 'admin_validation', static fn(): array => ['ok' => true, 'code' => 'validation_identity_verified']);
    adminHttpSame(null, $worker->runOnce(), 'validation worker requires an explicit current ID');
    adminHttpSame(null, $worker->runOnce(null), 'null current ID cannot drain validation work');
    adminHttpSame(null, $worker->runOnce('../../invalid-id'), 'malformed current ID cannot drain validation work');
    adminHttpSame(null, $worker->runOnce('900000000000001030'), 'validation worker refuses queued mock current ID');
    adminHttpSame(null, $worker->runOnce('900000000000001099'), 'validation worker never substitutes a missing ID');
    adminHttpSame(0, $checkCalls, 'ignored IDs invoke no business operation');
    adminHttpSame('validation_ready', $worker->runOnce('900000000000001031')['code'] ?? null, 'worker executes only selected validation interaction');
    adminHttpSame(1, $checkCalls, 'one selected job invokes one check');
    adminHttpSame(['900000000000001031'], array_map('strval', array_keys($sink->records)), 'only the selected interaction receives a followup');
    $mockState = $pdo->query("SELECT state FROM interaction_jobs WHERE interaction_id='900000000000001030'")->fetchColumn();
    adminHttpSame('pending', $mockState, 'unrelated mock job remains pending');
    $earlierState = $pdo->query("SELECT state FROM interaction_jobs WHERE interaction_id='900000000000001010'")->fetchColumn();
    adminHttpSame('pending', $earlierState, 'earlier accepted interaction is not drained by current request');
    adminHttpSame('', $pdo->query("SELECT token_ciphertext FROM interaction_jobs WHERE interaction_id='900000000000001031'")->fetchColumn(), 'completed validation callback token is erased');

    // Full public bootstrap stays offline through an injected runtime and driver.
    $publicRoot = dirname(__DIR__) . '/public';
    $configDirectory = $private . DIRECTORY_SEPARATOR . 'config'; mkdir($configDirectory, 0700);
    $configFile = $configDirectory . DIRECTORY_SEPARATOR . 'fictional-admin.php';
    $endpointDbPath = $private . DIRECTORY_SEPARATOR . 'endpoint.sqlite';
    $endpointConfig = $config; $endpointConfig['private_db_path'] = $endpointDbPath;
    $saveConfig = static function (array $value) use ($configFile): void {
        file_put_contents($configFile, '<?php return ' . var_export($value, true) . ';');
    };
    $saveConfig($endpointConfig);
    $events = new AdminHttpEvents();
    $driver = new AdminHttpPostResponse($events);
    $runtime = new AdminHttpRuntime($events);
    $endpoint = new PublicEndpoint($publicRoot, $driver, $runtime);
    $serverFor = static function (string $body) use ($headersFor): array {
        $headers = $headersFor($body, time());
        return ['REMOTE_ADDR' => '203.0.113.10', 'REQUEST_METHOD' => 'POST',
            'HTTP_X_SIGNATURE_TIMESTAMP' => $headers['X-Signature-Timestamp'],
            'HTTP_X_SIGNATURE_ED25519' => $headers['X-Signature-Ed25519']];
    };
    // Private PHP config output, including an accidental BOM, cannot escape into
    // Discord's response or the surrounding caller's output buffer.
    foreach ([
        ["\xEF\xBB\xBF<?php return " . var_export($endpointConfig, true) . ';', 'configuration_bom_detected'],
        ['FICTIONAL_TEST_CONFIG_OUTPUT_NOT_REAL<?php return ' . var_export($endpointConfig, true) . ';', 'configuration_output_detected'],
        ['<?php echo "FICTIONAL_TEST_CONFIG_OUTPUT_NOT_REAL"; return ' . var_export($endpointConfig, true) . ';', 'configuration_output_detected'],
        ['<?php echo "FICTIONAL_TEST_CONFIG_OUTPUT_NOT_REAL"; throw new RuntimeException("FICTIONAL_TEST_CONFIG_EXCEPTION_NOT_REAL");', 'configuration_require_failed'],
    ] as [$invalidSource, $expectedDiagnostic]) {
        file_put_contents($configFile, $invalidSource);
        ob_start(); echo 'outer-before'; $outerLevel = ob_get_level(); $rejected = false;
        try { PrivateConfig::load($configFile, $publicRoot, false); }
        catch (Throwable) { $rejected = true; }
        $restoredLevel = ob_get_level(); echo '-after'; $outerOutput = ob_get_clean();
        adminHttpCheck($rejected, 'private configuration with output or exception is rejected');
        adminHttpSame($outerLevel, $restoredLevel, 'config loading restores original output buffer depth');
        adminHttpSame('outer-before-after', $outerOutput, 'private config output is discarded without touching caller output');
        $body = $encode($ping);
        ob_start(); $badConfigResponse = $endpoint->prepare($serverFor($body), $body, $configFile)->response; $leakedOutput = ob_get_clean();
        adminHttpSame('', $leakedOutput, 'public bootstrap leaks no config output');
        adminHttpSame(['error' => 'administrator_validation_unavailable', 'diagnostic' => $expectedDiagnostic], $badConfigResponse->body, 'public bootstrap returns only fixed configuration failure classification');
    }
    $saveConfig($endpointConfig);
    $request = $payload; $request['id'] = '900000000000001040';
    $body = $encode($request); $server = $serverFor($body);
    $unsignedServer = $server; unset($unsignedServer['HTTP_X_SIGNATURE_ED25519']);
    adminHttpSame(401, $endpoint->prepare($unsignedServer, $body, $configFile)->response->status, 'public bootstrap verifies signature before opening SQLite');
    adminHttpCheck(!is_file($endpointDbPath), 'unsigned public request creates no database');
    $outOfScope = $request; $outOfScope['member']['user']['id'] = '900000000000000099';
    $outOfScopeBody = $encode($outOfScope);
    adminHttpSame(403, $endpoint->prepare($serverFor($outOfScopeBody), $outOfScopeBody, $configFile)->response->status, 'public bootstrap checks fixed owner before queue initialization');
    adminHttpCheck(!is_file($endpointDbPath), 'out-of-scope public request creates no database');
    adminHttpSame([], $events->items, 'rejected requests never invoke runtime or finish');
    adminHttpSame(503, $endpoint->prepare($server, $body, null)->response->status, 'public bootstrap requires explicit private configuration');
    $delivery = $endpoint->prepare($server, $body, $configFile);
    adminHttpSame(200, $delivery->response->status, 'signed fixed owner request accepted from external Discord address');
    adminHttpSame(['type' => 5, 'data' => ['flags' => 64]], $delivery->response->body, 'public endpoint prepares ephemeral ACK');
    adminHttpSame([], $runtime->runs, 'prepare never runs deferred network work');
    adminHttpSame(0, $driver->finishCalls, 'prepare does not finish the response prematurely');
    $emitted = null;
    $emitter = static function (Response $response) use ($events, &$emitted): void {
        $events->items[] = 'emit'; $emitted = $response->body;
    };
    adminHttpCheck($delivery->deliver($driver, $emitter), 'successful response delivery runs its fixed worker operation');
    adminHttpSame(['emit', 'finish', 'run:' . $request['id']], $events->items, 'ACK is emitted and finished before deferred execution');
    adminHttpSame(['type' => 5, 'data' => ['flags' => 64]], $emitted, 'emitter receives only public ACK JSON');
    adminHttpSame([['id' => $request['id'], 'mode' => 'admin_validation', 'private_root' => $private, 'public_root' => $publicRoot]], $runtime->runs, 'runtime receives only the current interaction and fixed bootstrap roots');
    adminHttpCheck(!$delivery->deliver($driver, $emitter), 'one delivery object cannot emit or dispatch twice');
    adminHttpSame(['emit', 'finish', 'run:' . $request['id']], $events->items, 'a second delivery attempt has no effects');
    ob_start(); $delivery->response->emit(); $responseJson = ob_get_clean();
    adminHttpSame($encode($delivery->response->body), $responseJson, 'real response emitter excludes private interaction metadata');

    $events->items = []; $runtime->runs = []; $driver->finished = false;
    $request['id'] = '900000000000001041'; $body = $encode($request);
    $failedFinish = $endpoint->prepare($serverFor($body), $body, $configFile);
    adminHttpCheck(!$failedFinish->deliver($driver, $emitter), 'failed response finish reports failure');
    adminHttpSame(['emit', 'finish'], $events->items, 'failed finish performs no deferred operation');
    adminHttpSame([], $runtime->runs, 'failed finish does not drain a queued job');
    adminHttpCheck(!$failedFinish->deliver($driver, $emitter), 'failed finish is not automatically retried by the same delivery');
    adminHttpSame(['emit', 'finish'], $events->items, 'failed finish retry never re-emits or runs work');
    $events->items = []; $driver->finished = true;
    $request['id'] = '900000000000001042'; $body = $encode($request);
    $lostFinish = $endpoint->prepare($serverFor($body), $body, $configFile);
    $driver->supported = false;
    adminHttpCheck(!$lostFinish->deliver($driver, $emitter), 'lost response support prevents deferred operation');
    adminHttpSame(['emit'], $events->items, 'unavailable driver never invokes finish or worker');

    $throwEvents = new AdminHttpEvents(); $throwDriver = new AdminHttpPostResponse($throwEvents);
    $afterEffects = 0;
    $throwDelivery = new \RadioStamp\Http\EndpointDelivery(new Response(200, ['type' => 5, 'data' => ['flags' => 64]]), static function () use (&$afterEffects): void { $afterEffects++; });
    ob_start();
    $emitFailed = $throwDelivery->deliver($throwDriver, static function (): void { throw new RuntimeException('FICTIONAL_EMIT_EXCEPTION_NOT_REAL'); });
    $emitFailureOutput = ob_get_clean();
    adminHttpCheck(!$emitFailed, 'emitter exception terminates delivery safely');
    adminHttpSame('', $emitFailureOutput, 'emitter failure prints no exception details');
    adminHttpSame(0, $throwDriver->finishCalls, 'emitter failure never attempts response finish');
    adminHttpSame(0, $afterEffects, 'emitter failure cannot run deferred work');
    adminHttpCheck(!$throwDelivery->deliver($throwDriver, static function (): void {}), 'emitter failure cannot be retried through the same delivery');

    // PING works on a freshly configured endpoint without either token key.
    $events->items = []; $driver->supported = true;
    $pingOnlyDbPath = $private . DIRECTORY_SEPARATOR . 'ping-only.sqlite';
    $pingOnlyConfig = $pingConfig; $pingOnlyConfig['private_db_path'] = $pingOnlyDbPath;
    $saveConfig($pingOnlyConfig);
    $body = $encode($ping);
    $pingDelivery = $endpoint->prepare($serverFor($body), $body, $configFile);
    adminHttpSame(['type' => 1], $pingDelivery->response->body, 'public PING needs no Bot or encryption token');
    adminHttpCheck($pingDelivery->deliver($driver, $emitter), 'keyless PING can be emitted');
    adminHttpSame(['emit'], $events->items, 'keyless PING never finishes for background work');
    adminHttpCheck(!is_file($pingOnlyDbPath), 'keyless public PING never creates SQLite storage');

    // Missing finish support cannot move a runtime/REST check before ACK.
    $events->items = []; $driver->supported = false;
    $fallbackConfig = $endpointConfig; $fallbackConfig['private_db_path'] = $private . DIRECTORY_SEPARATOR . 'fallback.sqlite';
    $saveConfig($fallbackConfig);
    $request = $checkRequest; $request['id'] = '900000000000001043'; $body = $encode($request);
    $fallback = $endpoint->prepare($serverFor($body), $body, $configFile);
    adminHttpSame(503, $fallback->response->status, 'public endpoint rejects work when finish support is absent');
    adminHttpSame('post_response_unavailable', $fallback->response->body['error'] ?? null, 'public endpoint exposes the fixed finisher requirement');
    adminHttpSame(0, $runtime->inlineCalls, 'missing finisher invokes no inline runtime or REST');
    adminHttpCheck(!is_file($fallbackConfig['private_db_path']), 'unsupported check does not create a queue or store callback tokens');
    adminHttpCheck($fallback->deliver($driver, $emitter), 'fixed rejection can be emitted without post-response support');
    adminHttpSame(['emit'], $events->items, 'unsupported check emits only the rejection');
    $events->items = [];
    $request = $payload; $request['id'] = '900000000000001044'; $body = $encode($request);
    adminHttpSame('post_response_unavailable', $endpoint->prepare($serverFor($body), $body, $configFile)->response->body['error'] ?? null, 'full public route rejects deferred actions when finish is unavailable');
    adminHttpSame([], $events->items, 'unsupported deferred action never invokes runtime');
    adminHttpCheck(!is_file($fallbackConfig['private_db_path']), 'unsupported deferred action does not initialize storage');

    // PING command registration is code-disabled for the one-off connection check.
    // The separate explicit CLI/runtime registration unit tests remain below.
    $driver->supported = true; $events->items = [];
    $saveConfig($endpointConfig); $body = $encode($ping);
    $plainPing = $endpoint->prepare($serverFor($body), $body, $configFile);
    adminHttpCheck($plainPing->deliver($driver, $emitter), 'ordinary configured PING is emitted');
    adminHttpSame(['emit'], $events->items, 'command registration remains off by default');
    $registrationConfig = $endpointConfig; $registrationConfig['command_registration_enabled'] = true;
    $saveConfig($registrationConfig); $events->items = [];
    $registrationPing = $endpoint->prepare($serverFor($body), $body, $configFile);
    adminHttpSame(0, $runtime->registrations, 'command registration does not run while preparing PING');
    adminHttpCheck($registrationPing->deliver($driver, $emitter), 'registration-flag PING still delivers protocol ACK');
    adminHttpSame(['emit'], $events->items, 'configuration cannot unlock code-disabled PING registration');
    adminHttpSame(0, $runtime->registrations, 'PING performs no registration operation even with a true configuration flag');

    $mockEndpointConfig = $mockConfig; $mockEndpointConfig['private_db_path'] = $private . DIRECTORY_SEPARATOR . 'mock-endpoint.sqlite';
    $saveConfig($mockEndpointConfig);
    $mockPing = ['application_id' => $mockConfig['application_id'], 'type' => 1]; $body = $encode($mockPing);
    adminHttpSame('local_administrator_validation_only', $endpoint->prepare($serverFor($body), $body, $configFile)->response->body['error'] ?? null, 'mock endpoint keeps its loopback boundary');

    // Production runtime composition, with its only transport replaced by a fake.
    // Inline mode must never inspect private storage, build cards, or perform a full check.
    $inlineHttp = new AdminHttpTransport([adminHttpJson(['id' => PrivateConfig::APPLICATION_ID, 'bot' => true])]);
    $factoryCalls = 0; $inlineOptions = [];
    $realInlineRuntime = new AdminValidationRuntime(static function (array $value, array $options) use ($inlineHttp, &$factoryCalls, &$inlineOptions): DiscordRestClient {
        $factoryCalls++; $inlineOptions = $options;
        return new DiscordRestClient($inlineHttp, $value['application_id'], $value['bot_token'], $options);
    });
    $inlineRuntimeConfig = $config;
    $inlineRuntimeConfig['token_encryption_key_hex'] = null;
    $inlineRuntimeConfig['private_db_path'] = $private . DIRECTORY_SEPARATOR . 'real-inline.sqlite';
    $realInlineResult = $realInlineRuntime->inlineCheck($inlineRuntimeConfig, hrtime(true) / 1e9 + 1.7);
    $canInline = extension_loaded('curl') && defined('CURL_VERSION_ASYNCHDNS') && (((curl_version()['features'] ?? 0) & CURL_VERSION_ASYNCHDNS) !== 0);
    if ($canInline) {
        adminHttpSame('bot_connection_verified', $realInlineResult['code'], 'real inline runtime verifies fixed Bot identity through fake HTTP');
        adminHttpSame(1, $factoryCalls, 'inline runtime creates one constrained client');
        adminHttpSame(1, count($inlineHttp->requests), 'real inline runtime makes exactly one HTTP read');
        adminHttpSame('GET', $inlineHttp->requests[0]['method'], 'inline runtime permits only GET');
        adminHttpSame('/api/v10/users/@me', $inlineHttp->requests[0]['path'], 'inline runtime reads only current Bot');
        adminHttpCheck($inlineHttp->requests[0]['bot_authorization'], 'self Bot request uses Bot authorization');
        adminHttpCheck($inlineHttp->requests[0]['timeout_ms'] <= 900 && $inlineHttp->requests[0]['connect_timeout_ms'] <= 300 && $inlineHttp->requests[0]['async_dns_required'], 'real runtime enforces bounded asynchronous-DNS transport');
        adminHttpSame(true, $inlineOptions['inline_self_bot_only'] ?? null, 'runtime selects restricted one-read client mode');
        adminHttpCheck(!isset($inlineOptions['state_store']), 'inline runtime does not persist REST state');
    } else {
        adminHttpSame('transport_async_dns_required', $realInlineResult['code'], 'unsupported inline transport fails closed');
        adminHttpSame(0, $factoryCalls, 'missing asynchronous DNS creates no client');
    }
    adminHttpCheck(!is_file($inlineRuntimeConfig['private_db_path']), 'real inline runtime creates no private database');
    adminHttpCheck(count(array_filter($realInlineResult['checks'], 'is_bool')) === count($realInlineResult['checks']), 'real inline capability report contains only booleans');
    $beforeFactory = $factoryCalls;
    $late = $realInlineRuntime->inlineCheck($inlineRuntimeConfig, hrtime(true) / 1e9 - 1);
    adminHttpSame($canInline ? 'validation_deadline_exceeded' : 'transport_async_dns_required', $late['code'], 'expired inline budget refuses network work');
    adminHttpSame($beforeFactory, $factoryCalls, 'expired inline budget does not even invoke client factory');
    $unsafeInline = $inlineRuntimeConfig; $unsafeInline['admin_user_ids'] = ['900000000000000099'];
    adminHttpSame('validation_configuration_required', $realInlineRuntime->inlineCheck($unsafeInline, hrtime(true) / 1e9 + 1.7)['code'], 'real inline runtime independently verifies fixed owner scope');
    adminHttpSame($beforeFactory, $factoryCalls, 'invalid runtime scope never creates transport');

    // Effective permissions include everyone, role, and Bot-member overwrites.
    $fixture = adminHttpBotFixture();
    $botHttp = new AdminHttpTransport(array_map('adminHttpJson', $fixture));
    $botClient = new DiscordRestClient($botHttp, $config['application_id'], $fictionalBotToken, ['enabled' => true]);
    $botReport = (new BotValidationCheck($botClient))->run();
    adminHttpSame(true, $botReport['ok'], 'full fixed-scope check accepts sufficient effective permissions');
    adminHttpSame('bot_check_passed', $botReport['code'], 'full check reports its fixed success code');
    adminHttpSame(8, count($botHttp->requests), 'full permission check uses exactly eight reads');
    adminHttpSame([
        '/api/v10/users/@me', '/api/v10/oauth2/applications/@me', '/api/v10/guilds/' . PrivateConfig::GUILD_ID,
        '/api/v10/guilds/' . PrivateConfig::GUILD_ID . '/members/' . PrivateConfig::APPLICATION_ID,
        '/api/v10/guilds/' . PrivateConfig::GUILD_ID . '/roles', '/api/v10/channels/' . PrivateConfig::GUIDE_ID,
        '/api/v10/channels/' . PrivateConfig::RECORDS_ID, '/api/v10/channels/' . PrivateConfig::VOICE_ID,
    ], array_column($botHttp->requests, 'path'), 'full check reads no unapproved guild, user, or channel');
    adminHttpSame(array_fill(0, 8, 'GET'), array_column($botHttp->requests, 'method'), 'full check has no write operation');
    adminHttpCheck(!in_array(false, $botReport['checks'], true), 'role/member allows correctly restore everyone/role denies');
    foreach ([
        [5, '2048', 'guide_permissions'], [6, '32768', 'records_permissions'], [7, '1048576', 'voice_permissions'],
    ] as [$index, $permission, $checkName]) {
        $changedFixture = adminHttpBotFixture();
        // A final member deny takes precedence over any preceding role allow.
        $changedFixture[$index]['permission_overwrites'] = [['id' => PrivateConfig::APPLICATION_ID, 'type' => 1, 'allow' => '0', 'deny' => $permission]];
        $fake = new AdminHttpTransport(array_map('adminHttpJson', $changedFixture));
        $report = (new BotValidationCheck(new DiscordRestClient($fake, $config['application_id'], $fictionalBotToken, ['enabled' => true])))->run();
        adminHttpSame(false, $report['ok'], 'missing required effective channel permission fails check');
        adminHttpSame(false, $report['checks'][$checkName], 'required permission failure identifies fixed channel');
    }
    foreach ([2, 4, 8, 16, 131072, 16777216, 268435456, 1099511627776] as $forbiddenPermission) {
        $changedFixture = adminHttpBotFixture();
        $changedFixture[4][1]['permissions'] = (string)((int)$changedFixture[4][1]['permissions'] | $forbiddenPermission);
        $fake = new AdminHttpTransport(array_map('adminHttpJson', $changedFixture));
        $report = (new BotValidationCheck(new DiscordRestClient($fake, $config['application_id'], $fictionalBotToken, ['enabled' => true])))->run();
        adminHttpSame(false, $report['ok'], 'moderation or management privilege is unnecessary and rejected');
        adminHttpSame(false, $report['checks']['restricted_permissions'], 'forbidden base permission never becomes validation-ready');
    }
    $changedFixture = adminHttpBotFixture();
    $changedFixture[6]['permission_overwrites'][] = ['id' => PrivateConfig::APPLICATION_ID, 'type' => 1, 'allow' => '268435456', 'deny' => '0'];
    $fake = new AdminHttpTransport(array_map('adminHttpJson', $changedFixture));
    $report = (new BotValidationCheck(new DiscordRestClient($fake, $config['application_id'], $fictionalBotToken, ['enabled' => true])))->run();
    adminHttpCheck(!$report['ok'] && !$report['checks']['restricted_permissions'], 'channel overwrite cannot introduce forbidden role management');
    $changedFixture = adminHttpBotFixture(); $changedFixture[4] = [$changedFixture[4][0]];
    $fake = new AdminHttpTransport(array_map('adminHttpJson', $changedFixture));
    $report = (new BotValidationCheck(new DiscordRestClient($fake, $config['application_id'], $fictionalBotToken, ['enabled' => true])))->run();
    adminHttpSame('bot_check_permissions_unknown', $report['code'], 'missing Bot role data never guesses effective permissions');
    adminHttpSame(5, count($fake->requests), 'unknown base permissions stop before channel reads');

    // A real runtime worker consumes the current ID through core, owner voice GET,
    // and original ephemeral PATCH. The time window is a private fictional fixture.
    $runtimeConfig = $config; $runtimeConfig['private_db_path'] = $private . DIRECTORY_SEPARATOR . 'runtime.sqlite';
    $runtimeConfig['font_candidates'] = [];
    $runtimeDb = new Database($runtimeConfig['private_db_path'], 200); $runtimePdo = $runtimeDb->pdo();
    $runtimeQueue = new InteractionQueue($runtimePdo, new TokenCipher($fictionalEncryptionKey));
    $instant = time(); $countDate = (new DateTimeImmutable('now', new DateTimeZone('Asia/Tokyo')))->format('Y-m-d');
    $sessionId = $countDate . '_0700';
    $runtimePdo->prepare("INSERT INTO sessions(id,scheduled_at,count_date,opens_at,unstarted_closes_at,status) VALUES (?,?,?,?,?,'open')")
        ->execute([$sessionId, $instant, $countDate, $instant - 60, $instant + 1800]);
    $runtimeHttp = new AdminHttpTransport(); $runtimeFactoryCalls = 0; $runtimeEvents = new AdminHttpEvents();
    $realRuntime = new AdminValidationRuntime(static function (array $value, array $options) use ($runtimeHttp, $runtimeEvents, &$runtimeFactoryCalls): DiscordRestClient {
        $runtimeEvents->items[] = 'client';
        $runtimeFactoryCalls++;
        return new DiscordRestClient($runtimeHttp, $value['application_id'], $value['bot_token'], $options);
    });
    $saveConfig($runtimeConfig); $runtimeDriver = new AdminHttpPostResponse($runtimeEvents);
    $realEndpoint = new PublicEndpoint($publicRoot, $runtimeDriver, $realRuntime);
    $startRequest = $payload; $startRequest['id'] = '900000000000001050'; $startRequest['data']['custom_id'] = 'radio:start:' . $sessionId;
    $body = $encode($startRequest);
    $realDelivery = $realEndpoint->prepare($serverFor($body), $body, $configFile);
    adminHttpSame(0, $runtimeFactoryCalls, 'real endpoint prepares ACK without creating a REST client');
    adminHttpSame([], $runtimeHttp->requests, 'real endpoint ACK path performs no REST request');
    $runtimeQueue->enqueue($job('900000000000001049', 'admin_validation'), $fictionalCallbackToken, $instant);
    $runtimeHttp->responses = [adminHttpJson(['id' => PrivateConfig::APPLICATION_ID, 'bot' => true]), adminHttpJson(['guild_id' => PrivateConfig::GUILD_ID, 'user_id' => PrivateConfig::OWNER_ID, 'channel_id' => PrivateConfig::VOICE_ID]), adminHttpJson(['id' => '900000000000001500'])];
    adminHttpCheck($realDelivery->deliver($runtimeDriver, static function (Response $response) use ($runtimeEvents): void { $runtimeEvents->items[] = 'emit'; }), 'real composed runtime delivers current start');
    adminHttpSame(['emit', 'finish', 'client'], $runtimeEvents->items, 'real composition finishes ACK before its transport factory executes');
    adminHttpSame(['GET', 'GET', 'PATCH'], array_column($runtimeHttp->requests, 'method'), 'real start verifies Bot identity before owner voice read and original edit');
    adminHttpSame('/api/v10/users/@me', $runtimeHttp->requests[0]['path'], 'every real start first verifies fixed Bot identity');
    adminHttpSame('/api/v10/guilds/' . PrivateConfig::GUILD_ID . '/voice-states/' . PrivateConfig::OWNER_ID, $runtimeHttp->requests[1]['path'], 'real start reads only fixed owner voice state');
    adminHttpSame('/api/v10/webhooks/' . PrivateConfig::APPLICATION_ID . '/[callback]/messages/@original', $runtimeHttp->requests[2]['path'], 'followup edits only original ephemeral response');
    adminHttpCheck($runtimeHttp->requests[0]['bot_authorization'] && $runtimeHttp->requests[1]['bot_authorization'] && !$runtimeHttp->requests[2]['bot_authorization'], 'Bot authorization never attaches to callback request');
    $followupPayload = json_decode($runtimeHttp->requests[2]['body'], true, 32, JSON_THROW_ON_ERROR);
    adminHttpSame(['parse' => [], 'users' => [], 'roles' => [], 'replied_user' => false], $followupPayload['allowed_mentions'], 'composed callback disables every mention class');
    adminHttpSame([], $followupPayload['attachments'], 'non-card start creates no PNG or attachment');
    adminHttpSame('started', $runtimePdo->query("SELECT result_code FROM interaction_jobs WHERE interaction_id='900000000000001050'")->fetchColumn(), 'real core result completes current queue job');
    adminHttpSame('pending', $runtimePdo->query("SELECT state FROM interaction_jobs WHERE interaction_id='900000000000001049'")->fetchColumn(), 'real runtime does not drain older validation job');
    adminHttpSame(0, (int)$runtimePdo->query('SELECT COUNT(*) FROM core_effects')->fetchColumn(), 'real start creates no public automation effect');
    $request = $startRequest; $request['id'] = '900000000000001051'; $request['data']['custom_id'] = 'radio:attend:' . $sessionId;
    $body = $encode($request); $runtimeHttp->requests = [];
    $runtimeHttp->responses = [adminHttpJson(['id' => PrivateConfig::APPLICATION_ID, 'bot' => true]), adminHttpJson(['user_id' => PrivateConfig::OWNER_ID, 'channel_id' => PrivateConfig::VOICE_ID]), adminHttpJson(['id' => '900000000000001501'])];
    $attendDelivery = $realEndpoint->prepare($serverFor($body), $body, $configFile);
    adminHttpCheck($attendDelivery->deliver($runtimeDriver, static function (Response $response): void {}), 'real composed owner attendance delivers');
    adminHttpSame(['GET', 'GET', 'PATCH'], array_column($runtimeHttp->requests, 'method'), 'real attendance verifies Bot before owner read and original edit');
    adminHttpSame(1, (int)$runtimePdo->query('SELECT COUNT(*) FROM attendance')->fetchColumn(), 'composed runtime stores one owner attendance');
    adminHttpSame(PrivateConfig::OWNER_ID, $runtimePdo->query('SELECT user_id FROM attendance')->fetchColumn(), 'runtime attendance belongs only to fixed owner');
    $runtimeHttp->requests = [];
    adminHttpSame('validation_no_pending_job', $realRuntime->runInteraction($runtimeConfig, $request['id'], $private, $publicRoot)['code'], 'completed current ID cannot run again');
    adminHttpSame([], $runtimeHttp->requests, 'completed ID replay makes no REST call');
    $beforeFactory = $runtimeFactoryCalls;
    adminHttpSame('validation_configuration_required', $realRuntime->runInteraction($runtimeConfig, '../../invalid-id', $private, $publicRoot)['code'], 'runtime validates current ID before creating client');
    adminHttpSame($beforeFactory, $runtimeFactoryCalls, 'invalid current ID never creates transport');

    // Full check runs through the same real worker with no usable font candidates.
    // Diagnostic jobs therefore must not eagerly construct the PNG renderer.
    $runtimeQueue->enqueue($job('900000000000001052', 'admin_validation'), $fictionalCallbackToken, time());
    $runtimeHttp->requests = []; $runtimeHttp->responses = [adminHttpJson(['id' => PrivateConfig::APPLICATION_ID, 'bot' => true]), ...array_map('adminHttpJson', adminHttpBotFixture()), adminHttpJson(['id' => '900000000000001502'])];
    $fullRuntimeResult = $realRuntime->runInteraction($runtimeConfig, '900000000000001052', $private, $publicRoot);
    adminHttpSame('done', $fullRuntimeResult['state'] ?? null, 'full diagnostic succeeds without loading a card font');
    adminHttpSame('bot_check_passed', $fullRuntimeResult['code'] ?? null, 'full runtime preserves successful permission diagnostic code');
    adminHttpSame([...array_fill(0, 9, 'GET'), 'PATCH'], array_column($runtimeHttp->requests, 'method'), 'full runtime verifies Bot before eight fixed check reads and one original edit');
    $diagnosticPayload = json_decode($runtimeHttp->requests[9]['body'], true, 32, JSON_THROW_ON_ERROR);
    adminHttpSame([], $diagnosticPayload['attachments'], 'full diagnostic sends no PNG');
    adminHttpSame([], $diagnosticPayload['components'], 'diagnostics never create session controls outside the shared VC controls message');
    adminHttpSame(\RadioStamp\Discord\Gateway::ALLOWED_MENTIONS, $diagnosticPayload['allowed_mentions'], 'diagnostic result cannot mention anyone');
    adminHttpCheck(str_contains($diagnosticPayload['content'], '接続確認に成功しました'), 'successful full check displays successful diagnostic text');
    adminHttpCheck(!is_dir($private . DIRECTORY_SEPARATOR . 'var'), 'private filesystem probing remains disabled by default');
    adminHttpCheck(!str_contains($runtimeHttp->requests[9]['body'], $private) && !str_contains($runtimeHttp->requests[9]['body'], $fictionalBotToken) && !str_contains($runtimeHttp->requests[9]['body'], $fictionalCallbackToken), 'full diagnostic response contains no private path or credentials');
    adminHttpSame('', $runtimePdo->query("SELECT token_ciphertext FROM interaction_jobs WHERE interaction_id='900000000000001052'")->fetchColumn(), 'real check completion immediately erases callback token');
    foreach (glob($runtimeConfig['private_db_path'] . '*') as $sqliteFile) {
        adminHttpCheck(!str_contains(file_get_contents($sqliteFile), $fictionalCallbackToken) && !str_contains(file_get_contents($sqliteFile), $fictionalBotToken), 'runtime SQLite and rate state contain no plaintext credentials');
    }

    $runtimeQueue->enqueue($job('900000000000001053', 'admin_validation'), $fictionalCallbackToken, time());
    $unsafeCheckFixture = adminHttpBotFixture(); $unsafeCheckFixture[4][1]['permissions'] = (string)((int)$unsafeCheckFixture[4][1]['permissions'] | 8);
    $runtimeHttp->requests = []; $runtimeHttp->responses = [adminHttpJson(['id' => PrivateConfig::APPLICATION_ID, 'bot' => true]), ...array_map('adminHttpJson', $unsafeCheckFixture), adminHttpJson(['id' => '900000000000001503'])];
    $unsafeCheck = $realRuntime->runInteraction($runtimeConfig, '900000000000001053', $private, $publicRoot);
    adminHttpSame('bot_check_permissions_failed', $unsafeCheck['code'] ?? null, 'failed effective permissions remain a failed full diagnostic');
    $unsafeDiagnosticPayload = json_decode($runtimeHttp->requests[9]['body'], true, 32, JSON_THROW_ON_ERROR);
    adminHttpSame([], $unsafeDiagnosticPayload['components'], 'failed permission check exposes no operation controls');

    // A valid credential for another Bot must never reach voice/core/card logic.
    $coreCountBefore = (int)$runtimePdo->query('SELECT COUNT(*) FROM core_interactions')->fetchColumn();
    $sessionBefore = $runtimePdo->query('SELECT * FROM sessions')->fetchAll(PDO::FETCH_ASSOC);
    foreach (['start', 'attend', 'card', 'memorial', 'check', 'status'] as $index => $action) {
        $id = (string)(900000000000001060 + $index);
        $identityJob = $job($id, 'admin_validation'); $identityJob['action'] = $action;
        $identityJob['target'] = match ($action) { 'start', 'attend' => $sessionId, 'card' => substr($countDate, 0, 7), 'memorial' => '10', 'status' => 'self', default => 'fixed_scope' };
        $runtimeQueue->enqueue($identityJob, $fictionalCallbackToken, time());
        $runtimeHttp->requests = []; $runtimeHttp->responses = [adminHttpJson(['id' => '900000000000000099', 'bot' => true]), adminHttpJson(['id' => '900000000000001599'])];
        $identityFailure = $realRuntime->runInteraction($runtimeConfig, $id, $private, $publicRoot);
        adminHttpSame('done', $identityFailure['state'] ?? null, 'wrong Bot receives only a fixed failure followup');
        adminHttpSame('validation_bot_identity_unverified', $identityFailure['code'] ?? null, 'all real operations require matching application Bot identity');
        adminHttpSame(['GET', 'PATCH'], array_column($runtimeHttp->requests, 'method'), 'wrong Bot triggers no owner voice, permission, or card operation');
        adminHttpSame('/api/v10/users/@me', $runtimeHttp->requests[0]['path'], 'wrong Bot is detected through fixed identity endpoint');
        $failurePayload = json_decode($runtimeHttp->requests[1]['body'], true, 32, JSON_THROW_ON_ERROR);
        adminHttpSame([], $failurePayload['attachments'], 'identity failure never renders or attaches PNG');
        adminHttpSame('', $runtimePdo->query("SELECT token_ciphertext FROM interaction_jobs WHERE interaction_id='" . $id . "'")->fetchColumn(), 'identity failure completion erases callback token');
    }
    adminHttpSame($coreCountBefore, (int)$runtimePdo->query('SELECT COUNT(*) FROM core_interactions')->fetchColumn(), 'wrong Bot cannot reach core interaction writes');
    adminHttpSame($sessionBefore, $runtimePdo->query('SELECT * FROM sessions')->fetchAll(PDO::FETCH_ASSOC), 'wrong Bot cannot start or mutate a local session');
    adminHttpSame(1, (int)$runtimePdo->query('SELECT COUNT(*) FROM attendance')->fetchColumn(), 'wrong Bot cannot increment owner attendance');

    $runtimeHttp->requests = []; $beforeFactory = $runtimeFactoryCalls;
    adminHttpSame('command_registration_disabled', $realRuntime->registerCommands($runtimeConfig)['code'], 'real command registration defaults off');
    adminHttpSame($beforeFactory, $runtimeFactoryCalls, 'disabled registration never creates a client');
    $registrationConfig = $runtimeConfig; $registrationConfig['command_registration_enabled'] = true;
    $unsafeFixture = adminHttpBotFixture(); $unsafeFixture[4][1]['permissions'] = (string)((int)$unsafeFixture[4][1]['permissions'] | 8);
    $runtimeHttp->responses = array_map('adminHttpJson', $unsafeFixture);
    $deniedRegistration = $realRuntime->registerCommands($registrationConfig);
    adminHttpSame(false, $deniedRegistration['ok'], 'unsafe effective permissions prevent command registration');
    adminHttpSame(array_fill(0, 8, 'GET'), array_column($runtimeHttp->requests, 'method'), 'failed permission preflight performs no command writes');
    $runtimeHttp->requests = [];
    $definitions = [];
    foreach (DiscordRestClient::validationCommandDefinitions() as $index => $definition) {
        $definitions[] = $definition + ['id' => (string)(900000000000001600 + $index), 'application_id' => PrivateConfig::APPLICATION_ID, 'guild_id' => PrivateConfig::GUILD_ID];
    }
    $runtimeHttp->responses = [...array_map('adminHttpJson', adminHttpBotFixture()), adminHttpJson($definitions)];
    $registered = $realRuntime->registerCommands($registrationConfig);
    adminHttpSame(true, $registered['ok'], 'real registration composes permission preflight and fixed command reconciliation');
    adminHttpSame('validation_commands_ready', $registered['code'], 'real registration returns its bounded success code');
    adminHttpSame(array_fill(0, 9, 'GET'), array_column($runtimeHttp->requests, 'method'), 'existing matching commands require only reads after eight-check preflight');
    adminHttpSame('/api/v10/applications/' . PrivateConfig::APPLICATION_ID . '/guilds/' . PrivateConfig::GUILD_ID . '/commands', $runtimeHttp->requests[8]['path'], 'registration reconciles only the fixed guild commands');

    // A signed PING may save only a fixed feature receipt in an existing private
    // var directory. The receipt is not a readiness report or token store.
    $capabilityRoot = $private . DIRECTORY_SEPARATOR . 'capability-fixture'; mkdir($capabilityRoot, 0700);
    $capabilityConfigDirectory = $capabilityRoot . DIRECTORY_SEPARATOR . 'config'; mkdir($capabilityConfigDirectory, 0700);
    $capabilityVar = $capabilityRoot . DIRECTORY_SEPARATOR . 'var'; mkdir($capabilityVar, 0700);
    $capabilityConfigFile = $capabilityConfigDirectory . DIRECTORY_SEPARATOR . 'fictional-admin.php';
    $capabilityFile = $capabilityVar . DIRECTORY_SEPARATOR . 'endpoint-capabilities.json';
    $capabilityConfig = $pingConfig; $capabilityConfig['private_db_path'] = $capabilityVar . DIRECTORY_SEPARATOR . 'unused.sqlite';
    $saveCapabilityConfig = static function (array $value) use ($capabilityConfigFile): void {
        file_put_contents($capabilityConfigFile, '<?php return ' . var_export($value, true) . ';');
    };
    $saveCapabilityConfig($capabilityConfig);
    $capabilityEvents = new AdminHttpEvents(); $capabilityDriver = new AdminHttpPostResponse($capabilityEvents, false);
    $capabilityRuntime = new AdminHttpRuntime($capabilityEvents);
    $capabilityEndpoint = new PublicEndpoint($publicRoot, $capabilityDriver, $capabilityRuntime);
    $body = $encode($ping); $server = $serverFor($body);
    $unsigned = $server; unset($unsigned['HTTP_X_SIGNATURE_ED25519']);
    adminHttpSame(401, $capabilityEndpoint->prepare($unsigned, $body, $capabilityConfigFile)->response->status, 'unsigned PING cannot save capability receipt');
    adminHttpCheck(!is_file($capabilityFile), 'unsigned PING creates no feature receipt');
    $wrongApplication = $ping; $wrongApplication['application_id'] = '900000000000000099'; $wrongBody = $encode($wrongApplication);
    adminHttpSame(403, $capabilityEndpoint->prepare($serverFor($wrongBody), $wrongBody, $capabilityConfigFile)->response->status, 'wrong application PING cannot save receipt');
    adminHttpCheck(!is_file($capabilityFile), 'wrong application creates no feature receipt');
    foreach ([null, false, 1, 'true'] as $flag) {
        $disabledCapabilities = $capabilityConfig;
        if ($flag !== null) { $disabledCapabilities['runtime_check_enabled'] = $flag; }
        $saveCapabilityConfig($disabledCapabilities);
        adminHttpSame(['type' => 1], $capabilityEndpoint->prepare($server, $body, $capabilityConfigFile)->response->body, 'capability recording defaults off without changing signed PING');
        adminHttpCheck(!is_file($capabilityFile), 'capability receipt requires explicit boolean opt in');
    }
    $capabilityConfig['runtime_check_enabled'] = true; $saveCapabilityConfig($capabilityConfig);
    $unsigned = $server; unset($unsigned['HTTP_X_SIGNATURE_ED25519']);
    adminHttpSame(401, $capabilityEndpoint->prepare($unsigned, $body, $capabilityConfigFile)->response->status, 'enabled receipt still requires valid signature');
    adminHttpCheck(!is_file($capabilityFile), 'enabled receipt is not written before signature verification');
    adminHttpSame(403, $capabilityEndpoint->prepare($serverFor($wrongBody), $wrongBody, $capabilityConfigFile)->response->status, 'enabled receipt still requires fixed application');
    adminHttpCheck(!is_file($capabilityFile), 'enabled receipt is not written for another application');
    $noFinishCapabilityPing = $capabilityEndpoint->prepare($server, $body, $capabilityConfigFile);
    adminHttpCheck($noFinishCapabilityPing->deliver($capabilityDriver, static function (): void {}), 'PING remains valid without a response finisher');
    adminHttpCheck(!is_file($capabilityFile), 'missing finisher never saves the optional receipt before PONG');
    $capabilityDriver->supported = true; $capabilityDriver->finished = false;
    $failedCapabilityPing = $capabilityEndpoint->prepare($server, $body, $capabilityConfigFile);
    adminHttpCheck(!is_file($capabilityFile), 'preparing PING performs no optional receipt write');
    adminHttpCheck(!$failedCapabilityPing->deliver($capabilityDriver, static function (): void {}), 'failed finish skips optional receipt work');
    adminHttpCheck(!is_file($capabilityFile), 'failed finish leaves no capability receipt');
    $capabilityDriver->finished = true; $capabilityEvents->items = [];
    $capabilityPing = $capabilityEndpoint->prepare($server, $body, $capabilityConfigFile);
    adminHttpSame(['type' => 1], $capabilityPing->response->body, 'keyless signed PING remains exact PONG');
    adminHttpCheck(!is_file($capabilityFile), 'optional features do not delay PING preparation');
    adminHttpCheck($capabilityPing->deliver($capabilityDriver, static function () use ($capabilityFile, $capabilityEvents): void {
        adminHttpCheck(!is_file($capabilityFile), 'receipt does not exist while ACK is emitted');
        $capabilityEvents->items[] = 'emit';
    }), 'successful finish permits the optional receipt');
    adminHttpSame(['emit', 'finish'], $capabilityEvents->items, 'receipt path finishes PONG before saving');
    adminHttpCheck(is_file($capabilityFile), 'explicitly enabled signed PING saves features only after finish');
    $capabilityBytes = file_get_contents($capabilityFile);
    $capabilities = json_decode($capabilityBytes, true, 16, JSON_THROW_ON_ERROR);
    $expectedFeatureNames = ['curl', 'gd', 'sodium', 'pdo_sqlite', 'sqlite_online_backup', 'mbstring', 'fastcgi_finish_request', 'symlink', 'curl_async_dns'];
    $actualFeatureNames = array_keys($capabilities); sort($expectedFeatureNames); sort($actualFeatureNames);
    adminHttpSame($expectedFeatureNames, $actualFeatureNames, 'feature receipt contains only the nine fixed capability names');
    adminHttpSame(9, count(array_filter($capabilities, 'is_bool')), 'every capability receipt value is boolean');
    foreach ([$fictionalBotToken, $fictionalCallbackToken, $fictionalPublicKey, $fictionalEncryptionKey, $capabilityRoot,
        PrivateConfig::APPLICATION_ID, PrivateConfig::GUILD_ID, PrivateConfig::OWNER_ID, 'timestamp', 'created_at', 'ready'] as $privateValue) {
        adminHttpCheck(!str_contains($capabilityBytes, $privateValue), 'feature receipt contains no credential, identity, path, timestamp, or readiness claim');
    }
    adminHttpCheck(!is_file($capabilityConfig['private_db_path']), 'capability PING requires no callback encryption or private database');
    adminHttpSame(['emit', 'finish'], $capabilityEvents->items, 'capability PING invokes only emit and formal finish before its local receipt');
    adminHttpSame([], $capabilityRuntime->runs, 'capability PING never runs the Bot worker');
    adminHttpSame(0, $capabilityRuntime->inlineCalls, 'capability PING invokes no inline REST');
    adminHttpSame(0, $capabilityRuntime->registrations, 'capability PING never registers commands');
    adminHttpSame([], glob($capabilityVar . DIRECTORY_SEPARATOR . '.endpoint-capabilities-*.tmp'), 'atomic receipt write leaves no temporary file');
    adminHttpCheck(EndpointCapabilities::save($capabilityRoot, $publicRoot), 'fixed receipt can be atomically replaced without new files');
    adminHttpSame([], glob($capabilityVar . DIRECTORY_SEPARATOR . '.endpoint-capabilities-*.tmp'), 'atomic replacement also leaves no temporary file');
    $existingReceipt = file_get_contents($capabilityFile);
    adminHttpCheck(!EndpointCapabilities::save($capabilityRoot . DIRECTORY_SEPARATOR . 'missing', $publicRoot), 'missing private root is rejected');
    adminHttpCheck(!EndpointCapabilities::save($capabilityRoot, $publicRoot . DIRECTORY_SEPARATOR . 'missing'), 'missing public root is rejected');
    adminHttpCheck(!EndpointCapabilities::save($capabilityRoot, $capabilityRoot), 'receipt inside a public root is rejected');
    adminHttpSame($existingReceipt, file_get_contents($capabilityFile), 'rejected paths cannot change existing private feature receipt');
    $noVarRoot = $private . DIRECTORY_SEPARATOR . 'capability-no-var'; mkdir($noVarRoot, 0700);
    adminHttpCheck(!EndpointCapabilities::save($noVarRoot, $publicRoot), 'feature save requires an existing private var directory');
    adminHttpCheck(!is_dir($noVarRoot . DIRECTORY_SEPARATOR . 'var'), 'feature save never creates missing storage directories');

    echo "Administrator HTTP: {$adminHttpAssertions} assertions passed\n";
} finally {
    $worker = $controller = $withoutFinish = $inlineController = $service = $queue = $pdo = $db = null;
    $realDelivery = $attendDelivery = $realEndpoint = $realRuntime = $runtimeQueue = $runtimePdo = $runtimeDb = null;
    if (isset($capabilityFile)) { @unlink($capabilityFile); }
    if (isset($capabilityConfigFile)) { @unlink($capabilityConfigFile); }
    if (isset($capabilityConfigDirectory)) { @rmdir($capabilityConfigDirectory); }
    if (isset($capabilityVar)) { @rmdir($capabilityVar); }
    if (isset($capabilityRoot)) { @rmdir($capabilityRoot); }
    if (isset($noVarRoot)) { @rmdir($noVarRoot); }
    if (isset($configFile)) { @unlink($configFile); }
    if (isset($configDirectory)) { @rmdir($configDirectory); }
    foreach (glob($private . DIRECTORY_SEPARATOR . '*') as $file) { @unlink($file); }
    @rmdir($private);
}
