<?php
declare(strict_types=1);
require_once __DIR__.'/artifact_directory.php';
require dirname(__DIR__) . '/bootstrap.php';

use RadioStamp\Discord\HttpAdapter;
use RadioStamp\Discord\HttpRequest;
use RadioStamp\Discord\HttpResponse;
use RadioStamp\Http\ConnectionValidationReceipt;
use RadioStamp\Http\PostResponseDriver;
use RadioStamp\Http\PrivateConfig;
use RadioStamp\Http\PublicEndpoint;
use RadioStamp\Http\Response;
use RadioStamp\Http\ValidationRuntime;

// Offline fixtures only. Never read a real config/admin.php or staging config.
$connectionChecks = 0;
$connectionSkips = [];
function connectionCheck(bool $condition, string $description): void
{
    global $connectionChecks;
    if (!$condition) { throw new RuntimeException('Connection receipt test failed: ' . $description); }
    ++$connectionChecks;
}
function connectionSame(mixed $expected, mixed $actual, string $description): void { connectionCheck($expected === $actual, $description); }

final class ConnectionReceiptEvents { public array $items = []; }
final class ConnectionReceiptDriver implements PostResponseDriver
{
    public function __construct(private readonly ConnectionReceiptEvents $events, public bool $supported = true, public bool $finished = true, public bool $throws = false) {}
    public function available(): bool { return $this->supported; }
    public function finish(): bool
    {
        $this->events->items[] = 'finish';
        if ($this->throws) { throw new RuntimeException('FICTIONAL_EXCEPTION_SECRET_NOT_REAL'); }
        return $this->finished;
    }
}
final class ConnectionReceiptNoRuntime implements ValidationRuntime
{
    public int $calls = 0;
    public function runInteraction(array $config, string $interactionId, string $privateRoot, string $publicRoot): array { ++$this->calls; throw new RuntimeException('Unexpected interaction runtime'); }
    public function registerCommands(array $config): array { ++$this->calls; throw new RuntimeException('Unexpected command registration'); }
    public function inlineCheck(array $config, float $deadline): array { ++$this->calls; throw new RuntimeException('Unexpected inline runtime'); }
}
final class ConnectionReceiptHttp implements HttpAdapter
{
    public array $requests = [];
    public ?Closure $beforeFirstRequest = null;
    public function __construct(public array $responses, private readonly ?ConnectionReceiptEvents $events = null) {}
    public function exchange(HttpRequest $request): HttpResponse
    {
        if ($this->events !== null) { $this->events->items[] = 'http'; }
        $this->requests[] = ['method' => $request->method(), 'path' => (string) parse_url($request->url(), PHP_URL_PATH),
            'timeout_ms' => $request->timeoutMs, 'connect_timeout_ms' => $request->connectTimeoutMs,
            'async_dns_required' => $request->asyncDnsRequired,
            'body_empty' => $request->body() === '',
            'fixed_origin' => parse_url($request->url(), PHP_URL_SCHEME) === 'https' && parse_url($request->url(), PHP_URL_HOST) === 'discord.com' &&
                parse_url($request->url(), PHP_URL_PORT) === null && parse_url($request->url(), PHP_URL_USER) === null &&
                parse_url($request->url(), PHP_URL_QUERY) === null && parse_url($request->url(), PHP_URL_FRAGMENT) === null];
        if (count($this->requests) === 1 && $this->beforeFirstRequest !== null) { ($this->beforeFirstRequest)(); }
        $next = array_shift($this->responses);
        if ($next instanceof Throwable) { throw $next; }
        if ($next instanceof Closure) { return $next($request); }
        return $next instanceof HttpResponse ? $next : new HttpResponse(0, [], '', 'fictional_empty');
    }
}
function connectionHttpJson(array $value): HttpResponse { return new HttpResponse(200, [], json_encode($value, JSON_THROW_ON_ERROR)); }
function connectionResponses(): array
{
    $role = '900000000000000090';
    $permissions = 1024 | 2048 | 32768 | 1048576;
    $secretDecoys = ['token' => 'FICTIONAL_RESPONSE_SECRET_NOT_REAL', 'cookie' => 'FICTIONAL_COOKIE_NOT_REAL', 'internal_path' => '/fictional/private/path'];
    return array_map('connectionHttpJson', [
        ['id' => PrivateConfig::APPLICATION_ID, 'bot' => true] + $secretDecoys,
        ['id' => PrivateConfig::APPLICATION_ID, 'bot' => ['id' => PrivateConfig::APPLICATION_ID], 'flags' => 0] + $secretDecoys,
        ['id' => PrivateConfig::GUILD_ID, 'owner_id' => PrivateConfig::OWNER_ID] + $secretDecoys,
        ['user' => ['id' => PrivateConfig::APPLICATION_ID], 'roles' => [$role], 'pending' => false] + $secretDecoys,
        [['id' => PrivateConfig::GUILD_ID, 'permissions' => '0', 'position' => 0], ['id' => $role, 'permissions' => (string) $permissions, 'position' => 1]],
        ['id' => PrivateConfig::GUIDE_ID, 'guild_id' => PrivateConfig::GUILD_ID, 'type' => 0, 'nsfw' => true, 'permission_overwrites' => []] + $secretDecoys,
        ['id' => PrivateConfig::RECORDS_ID, 'guild_id' => PrivateConfig::GUILD_ID, 'type' => 0, 'nsfw' => true, 'permission_overwrites' => []] + $secretDecoys,
        ['id' => PrivateConfig::VOICE_ID, 'guild_id' => PrivateConfig::GUILD_ID, 'type' => 2, 'nsfw' => true, 'permission_overwrites' => []] + $secretDecoys,
    ]);
}

$fixtureRoots = [];
$fixtureLinks = [];
$base = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'radio-connection-receipt-' . bin2hex(random_bytes(8));
mkdir($base, 0700);
$fictionalPair = sodium_crypto_sign_seed_keypair(hash('sha256', 'FICTIONAL_CONNECTION_RECEIPT_SIGNING_SEED_NOT_REAL', true));
$fictionalSigningSecret = sodium_crypto_sign_secretkey($fictionalPair);
$fictionalPublicKey = bin2hex(sodium_crypto_sign_publickey($fictionalPair));
$makeFixture = static function (string $name) use ($base, $fictionalPublicKey, &$fixtureRoots): array {
    $root = $base . DIRECTORY_SEPARATOR . $name;
    $private = $root . DIRECTORY_SEPARATOR . 'synthetic-private';
    $public = $root . DIRECTORY_SEPARATOR . 'public_html';
    foreach ([$root, $private, $public, $private . '/config', $private . '/var'] as $directory) { mkdir($directory, 0700); }
    $fixtureRoots[] = $root;
    $config = ['mode' => 'admin_validation', 'admin_validation_enabled' => true,
        'application_id' => PrivateConfig::APPLICATION_ID, 'guild_id' => PrivateConfig::GUILD_ID,
        'admin_user_ids' => [PrivateConfig::OWNER_ID], 'public_key_hex' => $fictionalPublicKey,
        'bot_token' => 'FICTIONAL_CONNECTION_BOT_TOKEN_NOT_REAL',
        'token_encryption_key_hex' => hash('sha256', 'FICTIONAL_CONNECTION_ENCRYPTION_KEY_NOT_REAL'),
        'private_db_path' => $private . '/var/fictional.sqlite',
        'text_channel_id' => PrivateConfig::GUIDE_ID, 'records_channel_id' => PrivateConfig::RECORDS_ID,
        'voice_channel_id' => PrivateConfig::VOICE_ID, 'timezone' => 'Asia/Tokyo',
        'member_actions_enabled' => false, 'live_automation_enabled' => false, 'role_ids' => [],
        'runtime_check_enabled' => true, 'command_registration_enabled' => false];
    return ['root' => $root, 'private' => $private, 'public' => $public,
        'config_file' => $private . '/config/fictional-receipt-fixture.php', 'config' => $config,
        'receipt' => $private . '/var/connection-validation-20261017.json',
        'attempt' => $private . '/var/connection-validation-20261017.attempt'];
};
$saveConfig = static function (array $fixture, ?array $config = null): void {
    file_put_contents($fixture['config_file'], '<?php return ' . var_export($config ?? $fixture['config'], true) . ';');
    chmod($fixture['config_file'], 0600);
};
$pingBody = static fn(array $changes = []): string => json_encode($changes + ['application_id' => PrivateConfig::APPLICATION_ID, 'type' => 1], JSON_THROW_ON_ERROR);
$serverFor = static function (string $body, array $changes = []) use ($fictionalSigningSecret): array {
    $stamp = (string) time();
    return $changes + ['REMOTE_ADDR' => '203.0.113.8', 'REQUEST_METHOD' => 'POST',
        'HTTP_X_SIGNATURE_TIMESTAMP' => $stamp, 'HTTP_X_SIGNATURE_ED25519' => bin2hex(sodium_crypto_sign_detached($stamp . $body, $fictionalSigningSecret))];
};
$validateReport = static function (array $fixture, array $report): void {
    connectionSame(['status', 'code', 'checks', 'checked_at_utc'], array_keys($report), 'report has only fixed fields');
    connectionCheck(in_array($report['status'], ['passed', 'failed'], true), 'report status is a fixed enum');
    connectionCheck(in_array($report['code'], ['bot_check_passed', 'bot_check_self_bot_failed', 'bot_check_self_application_failed',
        'bot_check_guild_failed', 'bot_check_bot_member_failed', 'bot_check_guild_roles_failed', 'bot_check_channel_failed',
        'bot_check_identity_failed', 'bot_check_permissions_unknown', 'bot_check_permissions_failed', 'connection_validation_failed'], true), 'report code is a fixed enum');
    connectionSame(['bot_identity', 'application_identity', 'guild_membership', 'guide_permissions', 'records_permissions', 'voice_permissions', 'restricted_permissions'], array_keys($report['checks']), 'report has seven allowlisted checks');
    foreach ($report['checks'] as $value) { connectionCheck(is_bool($value), 'report checks are boolean'); }
    connectionCheck(preg_match('/\A\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z\z/D', $report['checked_at_utc']) === 1, 'report UTC uses fixed timestamp format');
    $utc = DateTimeImmutable::createFromFormat('Y-m-d\TH:i:s\Z', $report['checked_at_utc'], new DateTimeZone('UTC'));
    connectionCheck($utc !== false && abs(time() - $utc->getTimestamp()) < 30, 'UTC value represents the check instant');
    $encoded = json_encode($report, JSON_THROW_ON_ERROR);
    foreach (['FICTIONAL_', 'token', 'cookie', 'internal_path', $fixture['private'], PrivateConfig::APPLICATION_ID, PrivateConfig::OWNER_ID, PrivateConfig::GUILD_ID] as $forbidden) {
        connectionCheck(!str_contains($encoded, $forbidden), 'report contains no secret, path, or identifier');
    }
};

try {
    $fixture = $makeFixture('signed-success'); $saveConfig($fixture);
    $configSnapshot = hash_file('sha256', $fixture['config_file']);
    $events = new ConnectionReceiptEvents(); $http = new ConnectionReceiptHttp(connectionResponses(), $events);
    $driver = new ConnectionReceiptDriver($events); $runtime = new ConnectionReceiptNoRuntime();
    $endpoint = new PublicEndpoint($fixture['public'], $driver, $runtime, new ConnectionValidationReceipt($http));
    $body = $pingBody();
    $delivery = $endpoint->prepare($serverFor($body), $body, $fixture['config_file']);
    connectionSame(['type' => 1], $delivery->response->body, 'signed PING remains type one');
    connectionSame(0, count($http->requests), 'no HTTP before response delivery');
    connectionCheck(!file_exists($fixture['receipt']) && !file_exists($fixture['attempt']), 'no connection files before finish');
    connectionCheck($delivery->deliver($driver, static function (Response $response) use ($events): void { $events->items[] = 'emit'; }), 'signed PING response succeeds with the one-off hook disabled');
    connectionSame(['emit', 'finish'], $events->items, 'feature-only receipt finishes PONG without a connection read');
    connectionSame(0, count($http->requests), 'signed PING sends no requests while the receipt hook is disabled');
    connectionCheck(!file_exists($fixture['receipt']) && !file_exists($fixture['attempt']), 'disabled PING creates no connection receipt or marker');
    connectionSame(0, $runtime->calls, 'no interaction, registration, or fallback action');
    // Preserve direct service coverage independently of the disabled public hook.
    $events->items = [];
    $direct = (new ConnectionValidationReceipt($http))->run($fixture['config'], $fixture['private'], $fixture['public']);
    connectionSame(['ok' => true, 'code' => 'connection_validation_recorded'], $direct, 'direct offline service records the fixed connection check');
    connectionSame(array_fill(0, 8, 'http'), $events->items, 'direct service performs exactly eight reads');
    connectionSame(8, count($http->requests), 'exactly eight fixed reads');
    connectionSame([
        '/api/v10/users/@me', '/api/v10/oauth2/applications/@me', '/api/v10/guilds/' . PrivateConfig::GUILD_ID,
        '/api/v10/guilds/' . PrivateConfig::GUILD_ID . '/members/' . PrivateConfig::APPLICATION_ID,
        '/api/v10/guilds/' . PrivateConfig::GUILD_ID . '/roles',
        '/api/v10/channels/' . PrivateConfig::GUIDE_ID, '/api/v10/channels/' . PrivateConfig::RECORDS_ID,
        '/api/v10/channels/' . PrivateConfig::VOICE_ID,
    ], array_column($http->requests, 'path'), 'only the fixed BotCheck resources are read');
    foreach ($http->requests as $request) {
        connectionSame('GET', $request['method'], 'no mutating Discord request');
        connectionCheck($request['fixed_origin'], 'request uses the fixed official HTTPS origin');
        connectionCheck($request['body_empty'], 'no request body is sent');
        connectionCheck($request['async_dns_required'], 'bounded reads require asynchronous DNS');
        connectionCheck($request['timeout_ms'] <= 1500 && $request['timeout_ms'] > 0, 'request total timeout bounded');
        connectionCheck($request['connect_timeout_ms'] <= 500 && $request['connect_timeout_ms'] > 0, 'connection timeout bounded');
    }
    connectionCheck(is_file($fixture['receipt']) && is_file($fixture['attempt']), 'private receipt and attempt marker exist');
    $report = json_decode(file_get_contents($fixture['receipt']), true, 16, JSON_THROW_ON_ERROR); $validateReport($fixture, $report);
    connectionSame('passed', $report['status'], 'valid connection fixture passes');
    connectionCheck(!file_exists($fixture['config']['private_db_path']), 'no application database is created');
    connectionSame([], glob($fixture['private'] . '/var/*.tmp'), 'no report temp files remain');
    $snapshot = file_get_contents($fixture['receipt']);
    $again = $endpoint->prepare($serverFor($body), $body, $fixture['config_file']);
    $again->deliver($driver, static function (Response $response): void {});
    connectionSame(8, count($http->requests), 'disabled signed PING cannot repeat connection reads');
    $againDirect = (new ConnectionValidationReceipt($http))->run($fixture['config'], $fixture['private'], $fixture['public']);
    connectionSame(['ok' => false, 'code' => 'connection_validation_already_attempted'], $againDirect, 'direct service replay also refuses a repeat attempt');
    connectionSame(8, count($http->requests), 'direct service replay sends no additional reads');
    connectionSame($snapshot, file_get_contents($fixture['receipt']), 'one-time receipt is not overwritten');
    connectionSame($configSnapshot, hash_file('sha256', $fixture['config_file']), 'the dedicated fictional configuration remains unchanged');

    // The method itself must refuse scope/flag/ready errors, even without HTTP.
    foreach (['flag_missing', 'flag_false', 'flag_null', 'flag_one', 'flag_string', 'command_flag_true', 'command_flag_missing', 'command_flag_null', 'command_flag_zero', 'command_flag_string', 'not_ready_token', 'not_ready_key', 'admin_disabled', 'mode_mock', 'timezone_other', 'wrong_application', 'wrong_guild', 'wrong_user', 'member_actions', 'automation', 'roles'] as $case) {
        $negative = $makeFixture($case); $bad = $negative['config'];
        match ($case) {
            'flag_missing' => $bad = array_diff_key($bad, ['runtime_check_enabled' => true]),
            'flag_false' => $bad['runtime_check_enabled'] = false,
            'flag_null' => $bad['runtime_check_enabled'] = null,
            'flag_one' => $bad['runtime_check_enabled'] = 1,
            'flag_string' => $bad['runtime_check_enabled'] = 'true',
            'command_flag_true' => $bad['command_registration_enabled'] = true,
            'command_flag_missing' => $bad = array_diff_key($bad, ['command_registration_enabled' => true]),
            'command_flag_null' => $bad['command_registration_enabled'] = null,
            'command_flag_zero' => $bad['command_registration_enabled'] = 0,
            'command_flag_string' => $bad['command_registration_enabled'] = 'false',
            'not_ready_token' => $bad['bot_token'] = null,
            'not_ready_key' => $bad['token_encryption_key_hex'] = null,
            'admin_disabled' => $bad['admin_validation_enabled'] = false,
            'mode_mock' => $bad['mode'] = 'mock',
            'timezone_other' => $bad['timezone'] = 'UTC',
            'wrong_application' => $bad['application_id'] = '900000000000000099',
            'wrong_guild' => $bad['guild_id'] = '900000000000000099',
            'wrong_user' => $bad['admin_user_ids'] = ['900000000000000099'],
            'member_actions' => $bad['member_actions_enabled'] = true,
            'automation' => $bad['live_automation_enabled'] = true,
            'roles' => $bad['role_ids'] = [1 => '900000000000000099'],
        };
        $fake = new ConnectionReceiptHttp(connectionResponses());
        $result = (new ConnectionValidationReceipt($fake))->run($bad, $negative['private'], $negative['public']);
        connectionCheck(($result['ok'] ?? true) === false, 'direct run fails closed for invalid validation scope');
        connectionSame(0, count($fake->requests), 'invalid direct run sends zero requests');
        connectionCheck(!file_exists($negative['receipt']) && !file_exists($negative['attempt']), 'invalid direct run leaves no receipt or marker');
    }

    foreach (['unsigned', 'bad_signature', 'different_application', 'GET', 'type_string', 'type_missing', 'type_float', 'type_boolean', 'not_ready', 'flag_false', 'command_flag_true', 'finish_false', 'finish_throw', 'driver_unavailable', 'driver_lost'] as $case) {
        $negative = $makeFixture('http-' . $case); $config = $negative['config'];
        if ($case === 'not_ready') { $config['bot_token'] = null; }
        if ($case === 'flag_false') { $config['runtime_check_enabled'] = false; }
        if ($case === 'command_flag_true') { $config['command_registration_enabled'] = true; }
        $saveConfig($negative, $config);
        $events = new ConnectionReceiptEvents(); $fake = new ConnectionReceiptHttp(connectionResponses(), $events);
        $driver = new ConnectionReceiptDriver($events, $case !== 'driver_unavailable', $case !== 'finish_false', $case === 'finish_throw');
        $runtime = new ConnectionReceiptNoRuntime();
        $endpoint = new PublicEndpoint($negative['public'], $driver, $runtime, new ConnectionValidationReceipt($fake));
        $request = $pingBody($case === 'different_application' ? ['application_id' => '900000000000000099'] : ($case === 'type_string' ? ['type' => '1'] : []));
        if ($case === 'type_missing') { $request = json_encode(['application_id' => PrivateConfig::APPLICATION_ID], JSON_THROW_ON_ERROR); }
        if ($case === 'type_float') { $request = '{"application_id":"' . PrivateConfig::APPLICATION_ID . '","type":1.0}'; }
        if ($case === 'type_boolean') { $request = $pingBody(['type' => true]); }
        $server = $serverFor($request);
        if ($case === 'unsigned') { unset($server['HTTP_X_SIGNATURE_ED25519']); }
        if ($case === 'bad_signature') { $server['HTTP_X_SIGNATURE_ED25519'] = str_repeat('00', 64); }
        if ($case === 'GET') { $server['REQUEST_METHOD'] = 'GET'; }
        $prepared = $endpoint->prepare($server, $request, $negative['config_file']);
        if ($case === 'driver_lost') { $driver->supported = false; }
        ob_start(); $prepared->deliver($driver, static function (Response $response): void {}); $output = ob_get_clean();
        connectionSame('', $output, 'guarded delivery emits no private details');
        connectionSame(0, count($fake->requests), 'HTTP guard sends zero requests');
        connectionSame(0, $runtime->calls, 'HTTP guard invokes no other runtime');
        connectionCheck(!file_exists($negative['receipt']) && !file_exists($negative['attempt']), 'HTTP guard creates no connection files');
    }

    // The new receipt hook must not attach itself to legacy owner interactions.
    // Their runtime is a non-network stub; these assertions concern the hook only.
    foreach ([2, 3] as $type) {
        $ordinary = $makeFixture('ordinary-type-' . $type); $saveConfig($ordinary);
        $events = new ConnectionReceiptEvents(); $fake = new ConnectionReceiptHttp(connectionResponses());
        $driver = new ConnectionReceiptDriver($events); $runtime = new ConnectionReceiptNoRuntime();
        $endpoint = new PublicEndpoint($ordinary['public'], $driver, $runtime, new ConnectionValidationReceipt($fake));
        $payload = ['application_id' => PrivateConfig::APPLICATION_ID, 'type' => $type,
            'id' => '900000000000000101', 'guild_id' => PrivateConfig::GUILD_ID, 'channel_id' => PrivateConfig::GUIDE_ID,
            'token' => 'FICTIONAL_CALLBACK_TOKEN_NOT_REAL', 'member' => ['user' => ['id' => PrivateConfig::OWNER_ID, 'username' => 'fictional']],
            'data' => $type === 2 ? ['name' => 'radio-check', 'type' => 1] : ['component_type' => 2, 'custom_id' => 'radio:card:2026-10']];
        $request = json_encode($payload, JSON_THROW_ON_ERROR);
        $prepared = $endpoint->prepare($serverFor($request), $request, $ordinary['config_file']);
        $prepared->deliver($driver, static function (Response $response): void {});
        connectionSame(0, count($fake->requests), 'ordinary interaction never invokes receipt network reads');
        connectionCheck(!file_exists($ordinary['receipt']) && !file_exists($ordinary['attempt']), 'ordinary interaction writes no connection receipt');
    }

    foreach (['receipt', 'marker', 'marker_directory', 'receipt_directory'] as $case) {
        $existing = $makeFixture('existing-' . $case);
        $path = str_starts_with($case, 'marker') ? $existing['attempt'] : $existing['receipt'];
        if (str_ends_with($case, 'directory')) { mkdir($path, 0700); } else { file_put_contents($path, 'FICTIONAL_EXISTING_SENTINEL_NOT_REAL'); chmod($path, 0600); }
        $fake = new ConnectionReceiptHttp(connectionResponses());
        $result = (new ConnectionValidationReceipt($fake))->run($existing['config'], $existing['private'], $existing['public']);
        connectionCheck(($result['ok'] ?? true) === false, 'existing one-time path refuses run');
        connectionSame(0, count($fake->requests), 'existing one-time path sends zero requests');
        if (!str_ends_with($case, 'directory')) { connectionSame('FICTIONAL_EXISTING_SENTINEL_NOT_REAL', file_get_contents($path), 'existing own fixture is not overwritten'); }
        if ($case === 'receipt') { connectionCheck(!file_exists($existing['attempt']), 'existing receipt refuses before marker creation'); }
    }

    $racing = $makeFixture('reentrant-attempt');
    $secondHttp = new ConnectionReceiptHttp(connectionResponses()); $secondResult = null;
    $firstHttp = new ConnectionReceiptHttp(connectionResponses());
    $firstHttp->beforeFirstRequest = static function () use (&$secondResult, $secondHttp, $racing): void {
        connectionCheck(is_file($racing['attempt']), 'exclusive marker is established before first request');
        $secondResult = (new ConnectionValidationReceipt($secondHttp))->run($racing['config'], $racing['private'], $racing['public']);
    };
    $firstResult = (new ConnectionValidationReceipt($firstHttp))->run($racing['config'], $racing['private'], $racing['public']);
    connectionSame(true, $firstResult['ok'], 'first attempt owns the exclusive marker');
    connectionSame(false, $secondResult['ok'], 'overlapping attempt fails closed');
    connectionSame(0, count($secondHttp->requests), 'overlapping attempt cannot reach network');
    connectionSame(8, count($firstHttp->requests), 'exclusive owner performs exactly eight reads');

    foreach (['unauthorized', 'forbidden', 'unavailable', 'exception', 'unknown_error', 'wrong_bot', 'secret_body'] as $case) {
        $failed = $makeFixture('failure-' . $case);
        $badResponse = match ($case) {
            'unauthorized' => new HttpResponse(401, [], '{"token":"FICTIONAL_RESPONSE_SECRET_NOT_REAL"}'),
            'forbidden' => new HttpResponse(403, [], '{"cookie":"FICTIONAL_COOKIE_NOT_REAL"}'),
            'unavailable' => new HttpResponse(503),
            'exception' => new RuntimeException('FICTIONAL_EXCEPTION_SECRET_NOT_REAL'),
            'unknown_error' => new HttpResponse(0, [], '', 'FICTIONAL_ERROR_SECRET_NOT_REAL'),
            'wrong_bot' => connectionHttpJson(['id' => '900000000000000099', 'bot' => true]),
            'secret_body' => new HttpResponse(200, [], 'FICTIONAL_INVALID_BODY_SECRET_NOT_REAL'),
        };
        $fake = new ConnectionReceiptHttp([$badResponse]);
        ob_start(); $result = (new ConnectionValidationReceipt($fake))->run($failed['config'], $failed['private'], $failed['public']); $output = ob_get_clean();
        connectionSame('', $output, 'connection failure emits no exception or raw body');
        connectionSame(1, count($fake->requests), 'read failure stops without retry or later reads');
        connectionCheck(is_file($failed['receipt']) && is_file($failed['attempt']), 'failed one-time run persists a filtered receipt and marker');
        $report = json_decode(file_get_contents($failed['receipt']), true, 16, JSON_THROW_ON_ERROR); $validateReport($failed, $report);
        connectionSame('failed', $report['status'], 'failed read is not reported as success');
        (new ConnectionValidationReceipt($fake))->run($failed['config'], $failed['private'], $failed['public']);
        connectionSame(1, count($fake->requests), 'failed attempt cannot retry later');
    }

    // One deliberately slow fake ignores the per-request timeout. This checks
    // the separate whole-operation deadline without ever opening a connection.
    $expired = $makeFixture('whole-operation-deadline');
    $slowHttp = new ConnectionReceiptHttp([static function (HttpRequest $request): HttpResponse {
        usleep(12_100_000);
        return connectionHttpJson(['id' => PrivateConfig::APPLICATION_ID, 'bot' => true]);
    }]);
    $expiredResult = (new ConnectionValidationReceipt($slowHttp))->run($expired['config'], $expired['private'], $expired['public']);
    connectionSame(false, $expiredResult['ok'], 'whole-operation deadline never reports successful validation');
    connectionSame(1, count($slowHttp->requests), 'whole-operation deadline stops every later read');
    $expiredReport = json_decode(file_get_contents($expired['receipt']), true, 16, JSON_THROW_ON_ERROR); $validateReport($expired, $expiredReport);
    connectionSame('failed', $expiredReport['status'], 'deadline result is a failed private receipt');

    // No source other than dedicated fixtures is inspected or removed below.
    foreach (['missing_private', 'missing_var', 'private_in_public', 'var_in_public', 'relative_private'] as $case) {
        $badPath = $makeFixture('path-' . $case); $privatePath = $badPath['private'];
        if ($case === 'missing_private') { $privatePath .= '/missing'; }
        if ($case === 'missing_var') { rmdir($privatePath . '/var'); }
        if ($case === 'private_in_public') { $privatePath = $badPath['public']; mkdir($privatePath . '/var', 0700); }
        if ($case === 'var_in_public') { $privatePath = $badPath['public'] . '/nested'; mkdir($privatePath, 0700); mkdir($privatePath . '/var', 0700); }
        if ($case === 'relative_private') { $privatePath = 'relative-fixture-path'; }
        $fake = new ConnectionReceiptHttp(connectionResponses());
        $result = (new ConnectionValidationReceipt($fake))->run($badPath['config'], $privatePath, $badPath['public']);
        connectionCheck(($result['ok'] ?? true) === false, 'invalid storage path fails closed');
        connectionSame(0, count($fake->requests), 'invalid storage path sends zero requests');
    }

    if (PHP_OS_FAMILY === 'Windows') {
        $connectionSkips[] = 'POSIX mode measurement unavailable on Windows';
    } else {
        foreach ([$fixture['receipt'], $fixture['attempt']] as $path) {
            clearstatcache(true, $path); connectionSame(0600, fileperms($path) & 0777, 'connection file has mode 0600');
        }
        foreach (['private', 'var'] as $case) {
            $exposed = $makeFixture('perms-' . $case); $target = $case === 'private' ? $exposed['private'] : $exposed['private'] . '/var';
            chmod($target, 0755); $fake = new ConnectionReceiptHttp(connectionResponses());
            $result = (new ConnectionValidationReceipt($fake))->run($exposed['config'], $exposed['private'], $exposed['public']);
            connectionCheck(($result['ok'] ?? true) === false && count($fake->requests) === 0, 'world-readable private directory refuses network');
            chmod($target, 0700);
        }
    }

    $linked = $makeFixture('symlink-tests');
    $outside = $linked['root'] . '/synthetic-link-target'; mkdir($outside, 0700);
    $link = $linked['private'] . '/var/dangling-probe';
    if (!function_exists('symlink') || !@symlink($outside . '/missing', $link)) {
        $connectionSkips[] = 'Symbolic-link creation unavailable';
    } else {
        unlink($link);
        foreach (['var', 'receipt', 'attempt'] as $case) {
            $symlinkFixture = $makeFixture('symlink-' . $case);
            $target = $case === 'var' ? $symlinkFixture['private'] . '/var' : $symlinkFixture[$case === 'receipt' ? 'receipt' : 'attempt'];
            if ($case === 'var') { rmdir($target); }
            connectionCheck(symlink($case === 'var' ? $outside : $outside . '/missing', $target), 'own symlink fixture created');
            $fixtureLinks[] = $target;
            $fake = new ConnectionReceiptHttp(connectionResponses());
            $result = (new ConnectionValidationReceipt($fake))->run($symlinkFixture['config'], $symlinkFixture['private'], $symlinkFixture['public']);
            connectionCheck(($result['ok'] ?? true) === false && count($fake->requests) === 0, 'private symlink refuses network');
        }
    }

    echo 'Connection receipt: ' . $connectionChecks . ' assertions passed (fake HTTP only); ' . count($connectionSkips) . " platform-dependent groups skipped.\n";
} finally {
    // Resolve our single random temp root once, then remove only known own tree.
    foreach ($fixtureLinks as $link) { if (is_link($link)) { unlink($link); } }
    $ownedRoot = realpath($base);
    if ($ownedRoot !== false && dirname($ownedRoot) === realpath(sys_get_temp_dir()) && str_starts_with(basename($ownedRoot), 'radio-connection-receipt-')) {
        $removeOwned = static function (string $directory) use (&$removeOwned, $ownedRoot): void {
            foreach (new DirectoryIterator($directory) as $entry) {
                if ($entry->isDot()) { continue; }
                $path = $entry->getPathname();
                if ($entry->isLink()) { unlink($path); }
                elseif ($entry->isDir()) { $removeOwned($path); rmdir($path); }
                else { unlink($path); }
            }
        };
        $removeOwned($ownedRoot); rmdir($ownedRoot);
    }
}
