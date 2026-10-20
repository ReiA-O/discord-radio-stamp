<?php
declare(strict_types=1);
require_once __DIR__.'/artifact_directory.php';
require dirname(__DIR__) . '/bootstrap.php';

use RadioStamp\Core\Database;
use RadioStamp\Core\FrozenClock;
use RadioStamp\Core\RadioService;
use RadioStamp\Discord\DiscordRestClient;
use RadioStamp\Discord\HttpAdapter;
use RadioStamp\Discord\HttpRequest;
use RadioStamp\Discord\HttpResponse;
use RadioStamp\Discord\MockGateway;
use RadioStamp\Discord\RestGateway;

$rsAdminCoreAssertions = 0;
function rsAdminCoreAssert(bool $condition, string $message): void
{
    global $rsAdminCoreAssertions; $rsAdminCoreAssertions++;
    if (!$condition) { throw new RuntimeException('Administrator core assertion failed: ' . $message); }
}
function rsAdminCoreSame(mixed $expected, mixed $actual, string $message): void { rsAdminCoreAssert($expected === $actual, $message); }

/** An injected offline transport. Headers and tokens are never retained or printed. */
final class RsAdminCoreHttp implements HttpAdapter
{
    public array $responses = [];
    public array $requests = [];
    public ?\Closure $beforeResponse = null;
    public function exchange(HttpRequest $request): HttpResponse
    {
        $this->requests[] = ['method' => $request->method(), 'path' => parse_url($request->url(), PHP_URL_PATH)];
        if ($this->responses === []) { throw new RuntimeException('No fictional HTTP response queued.'); }
        if ($this->beforeResponse !== null) { ($this->beforeResponse)(); }
        return array_shift($this->responses);
    }
    public function present(): void
    {
        $this->responses[] = new HttpResponse(200, [], json_encode(['guild_id' => RestGateway::GUILD_ID, 'user_id' => RestGateway::ADMIN_USER_ID, 'channel_id' => RestGateway::VOICE_CHANNEL_ID, 'self_mute' => true, 'self_deaf' => true], JSON_THROW_ON_ERROR));
    }
}

$dir = sys_get_temp_dir() . '/radio-stamp-admin-core-' . bin2hex(random_bytes(8)); mkdir($dir, 0700);
try {
    $db = new Database($dir . '/application.sqlite');
    $clock = new FrozenClock('2026-10-17 07:00:00+09:00');
    $http = new RsAdminCoreHttp();
    $client = new DiscordRestClient($http, '800000000000001003', 'fictional_test_token_for_mock_only', ['enabled' => true, 'now' => static fn(): float => (float)$clock->now()->getTimestamp()]);
    $gateway = new RestGateway($client, $clock);
    $config = [
        'mode' => 'admin_validation', 'admin_validation_enabled' => true,
        'admin_user_ids' => [RestGateway::ADMIN_USER_ID], 'guild_id' => RestGateway::GUILD_ID,
        'voice_channel_id' => RestGateway::VOICE_CHANNEL_ID, 'text_channel_id' => '800000000000001005',
        'records_channel_id' => '800000000000001006', 'member_actions_enabled' => false,
        'role_ids' => [], 'live_automation_enabled' => false,
    ];
    $service = new RadioService($db, $gateway, $clock, $config);
    rsAdminCoreSame([], $http->requests, 'construction sends no request');
    foreach ([
        ['admin_validation_enabled' => false], ['member_actions_enabled' => true], ['role_ids' => [1 => 'fake-role']],
        ['live_automation_enabled' => true], ['admin_user_ids' => []],
        ['admin_user_ids' => [RestGateway::ADMIN_USER_ID, '123456789012345679']],
        ['admin_user_ids' => ['123456789012345679']], ['guild_id' => '123456789012345679'],
        ['voice_channel_id' => '123456789012345679'], ['mode' => 'live'], ['mode' => 'mock'],
    ] as $unsafe) {
        try { new RadioService($db, $gateway, $clock, $unsafe + $config); rsAdminCoreAssert(false, 'unsafe validation configuration rejected'); }
        catch (LogicException) { rsAdminCoreAssert(true, 'unsafe validation configuration rejected'); }
    }
    foreach (['member_actions_enabled', 'role_ids', 'live_automation_enabled'] as $requiredFlag) {
        $missing = $config; unset($missing[$requiredFlag]);
        try { new RadioService($db, $gateway, $clock, $missing); rsAdminCoreAssert(false, 'explicit disabled flag required'); }
        catch (LogicException) { rsAdminCoreAssert(true, 'explicit disabled flag required'); }
    }
    try { new RadioService($db, new MockGateway($clock), $clock, $config); rsAdminCoreAssert(false, 'mock cannot impersonate dedicated validation transport'); }
    catch (LogicException) { rsAdminCoreAssert(true, 'mock cannot impersonate dedicated validation transport'); }
    rsAdminCoreSame([], $http->requests, 'invalid configuration never sends requests');

    $otherUser = '123456789012345679'; $sessionId = '2026-10-17_0700';
    rsAdminCoreSame('admin_validation_forbidden', $service->start($sessionId, $otherUser, 'other-start')['code'], 'other user cannot start');
    rsAdminCoreSame('admin_validation_forbidden', $service->attend($sessionId, $otherUser, 'other-attend')['code'], 'other user cannot attend');
    rsAdminCoreSame('admin_validation_forbidden', $service->cardData($otherUser, 'other user', '2026-10')['code'], 'other user cannot read card data');
    rsAdminCoreSame([], $http->requests, 'forbidden users rejected before REST');
    rsAdminCoreSame(0, (int)$db->pdo()->query('SELECT COUNT(*) FROM sessions')->fetchColumn(), 'forbidden requests do not create sessions');
    rsAdminCoreSame('live_automation_disabled', $service->tick()['code'], 'live tick rejected');
    rsAdminCoreSame(0, (int)$db->pdo()->query('SELECT COUNT(*) FROM sessions')->fetchColumn(), 'live tick does not even plan sessions');
    rsAdminCoreSame('session_not_open', $service->start('2026-10-17_2200', RestGateway::ADMIN_USER_ID, 'future-slot')['code'], 'future session stays unavailable');
    rsAdminCoreSame('session_closed', $service->start('2026-10-17_0400', RestGateway::ADMIN_USER_ID, 'old-slot')['code'], 'past session stays unavailable');
    rsAdminCoreSame('invalid_request', $service->start('2026-02-31_0700', RestGateway::ADMIN_USER_ID, 'invalid-date')['code'], 'invalid calendar target rejected');
    rsAdminCoreSame([], $http->requests, 'outside-window requests do not read REST presence');

    $http->present(); $start = $service->start($sessionId, RestGateway::ADMIN_USER_ID, 'owner-start');
    rsAdminCoreSame('started', $start['code'], 'owner can validate start through actual REST boundary with fake HTTP');
    rsAdminCoreSame(1, count($http->requests), 'start only reads owner voice once');
    rsAdminCoreSame(1, (int)$db->pdo()->query('SELECT COUNT(*) FROM sessions')->fetchColumn(), 'current slot prepared locally without worker tick');
    rsAdminCoreSame($start, $service->start($sessionId, RestGateway::ADMIN_USER_ID, 'owner-start'), 'start replay deterministic');
    rsAdminCoreSame(1, count($http->requests), 'start replay makes no REST request');
    $http->present(); $attend = $service->attend($sessionId, RestGateway::ADMIN_USER_ID, 'owner-attend');
    rsAdminCoreSame('attended', $attend['code'], 'owner can record own attendance');
    rsAdminCoreSame(1, $attend['total_count'], 'validation attendance counted once');
    rsAdminCoreSame($attend, $service->attend($sessionId, RestGateway::ADMIN_USER_ID, 'owner-attend'), 'attendance replay deterministic');
    rsAdminCoreSame(2, count($http->requests), 'attendance replay makes no REST request');
    $card = $service->cardData(RestGateway::ADMIN_USER_ID, '本人検証', '2026-10');
    rsAdminCoreSame(1, $card['total_count'], 'owner card reads own local count');
    rsAdminCoreSame(['IC'], $card['days']['2026-10-17'], 'owner card contains local validation stamp');
    rsAdminCoreSame(2, count($http->requests), 'card reading has no REST request');
    rsAdminCoreSame(0, (int)$db->pdo()->query('SELECT COUNT(*) FROM core_effects')->fetchColumn(), 'start and attendance create no public effect or outbox');

    // Even leftover mock effects must never run after switching to administrator validation.
    $db->pdo()->exec("INSERT INTO core_effects(effect_key,kind,payload_json,status) VALUES ('old-public-effect','warning','{}','pending')");
    $clock->advance(900);
    rsAdminCoreSame('session_closed', $service->attend($sessionId, RestGateway::ADMIN_USER_ID, 'after-cutoff')['code'], 'fifteen-minute attendance cutoff retained');
    rsAdminCoreSame($attend, $service->attend($sessionId, RestGateway::ADMIN_USER_ID, 'owner-attend'), 'replay remains same after cutoff');
    rsAdminCoreSame('live_automation_disabled', $service->tick()['code'], 'deadline never activates automated live closure');
    rsAdminCoreSame(2, count($http->requests), 'cutoff, replay and tick make no new request');
    $effect = $db->pdo()->query("SELECT status,attempts FROM core_effects WHERE effect_key='old-public-effect'")->fetch();
    rsAdminCoreSame('pending', $effect['status'], 'pending public effect stays untouched');
    rsAdminCoreSame(0, (int)$effect['attempts'], 'pending public effect not claimed');
    $session = $db->pdo()->query("SELECT status,warning_sent_at FROM sessions WHERE id='2026-10-17_0700'")->fetch();
    rsAdminCoreSame('started', $session['status'], 'live tick does not mutate room lifecycle');
    rsAdminCoreSame(null, $session['warning_sent_at'], 'no close warning generated');

    // Midnight keeps the scheduled date even during the preceding day's opening window.
    $clock->set('2026-10-17 23:35:00+09:00');
    $http->present(); rsAdminCoreSame('started', $service->start('2026-10-18_0000', RestGateway::ADMIN_USER_ID, 'midnight-start')['code'], 'midnight owner start during previous-day opening');
    $http->present(); $midnight = $service->attend('2026-10-18_0000', RestGateway::ADMIN_USER_ID, 'midnight-attend');
    rsAdminCoreSame('2026-10-18', $midnight['count_date'], 'midnight validation counts scheduled day');
    rsAdminCoreSame(2, $midnight['total_count'], 'second validation session counted');
    rsAdminCoreSame(1, (int)$db->pdo()->query('SELECT COUNT(*) FROM core_effects')->fetchColumn(), 'only deliberately seeded old effect exists');

    // REST failures are unknown, never self-reported presence or permission to mutate members.
    $clock->set('2026-10-18 04:00:00+09:00');
    $http->responses[] = new HttpResponse(503, [], '{}');
    rsAdminCoreSame('voice_unknown', $service->start('2026-10-18_0400', RestGateway::ADMIN_USER_ID, 'failed-voice-read')['code'], 'REST failure does not authorize owner start');
    $http->responses[] = new HttpResponse(200, [], json_encode(['user_id' => RestGateway::ADMIN_USER_ID, 'channel_id' => '800000000000001008'], JSON_THROW_ON_ERROR));
    rsAdminCoreSame('not_in_voice', $service->start('2026-10-18_0400', RestGateway::ADMIN_USER_ID, 'other-voice-read')['code'], 'known presence in another VC does not authorize start');
    $http->present(); $http->beforeResponse = static function () use ($clock): void { $clock->advance(6); };
    rsAdminCoreSame('voice_stale', $service->start('2026-10-18_0400', RestGateway::ADMIN_USER_ID, 'slow-voice-read')['code'], 'voice observation aged by a slow response is rejected before writing attendance');
    $http->beforeResponse = null;
    rsAdminCoreSame(2, $service->cardData(RestGateway::ADMIN_USER_ID, '本人検証', '2026-10')['total_count'], 'failed, other-VC, and stale reads add no attendance');
    $requestCount = count($http->requests);
    rsAdminCoreSame(false, $gateway->roster(RestGateway::GUILD_ID, RestGateway::VOICE_CHANNEL_ID)->known, 'REST does not claim complete roster');
    rsAdminCoreSame($requestCount, count($http->requests), 'roster unknown has no enumerating network request');
    foreach ($http->requests as $request) {
        rsAdminCoreSame('GET', $request['method'], 'core validation performs only GET');
        rsAdminCoreAssert(str_ends_with((string)$request['path'], '/guilds/' . RestGateway::GUILD_ID . '/voice-states/' . RestGateway::ADMIN_USER_ID), 'core validation only queries fixed owner presence');
    }
    $stored = implode('', $db->pdo()->query('SELECT result_json FROM core_interactions')->fetchAll(PDO::FETCH_COLUMN));
    rsAdminCoreAssert(!str_contains($stored, 'fictional_test_token_for_mock_only'), 'no fictional credentials persisted in core records');

    $disabledDb = new Database($dir . '/disabled-application.sqlite');
    $disabledHttp = new RsAdminCoreHttp();
    $disabledClient = new DiscordRestClient($disabledHttp, '800000000000001003', 'fictional_test_token_for_mock_only');
    $disabledGateway = new RestGateway($disabledClient, $clock);
    $disabledService = new RadioService($disabledDb, $disabledGateway, $clock, $config);
    rsAdminCoreSame('voice_unknown', $disabledService->start('2026-10-18_0400', RestGateway::ADMIN_USER_ID, 'disabled-client-start')['code'], 'REST is disabled by default even with a valid owner context');
    rsAdminCoreSame([], $disabledHttp->requests, 'disabled-by-default transport makes no HTTP request');
    rsAdminCoreSame(0, (int)$disabledDb->pdo()->query('SELECT COUNT(*) FROM participants')->fetchColumn(), 'disabled transport cannot record attendance');
    rsAdminCoreSame(0, (int)$disabledDb->pdo()->query('SELECT COUNT(*) FROM core_effects')->fetchColumn(), 'disabled transport has no public effects');
    echo 'administrator core: ' . $rsAdminCoreAssertions . " assertions passed; injected fake HTTP only\n";
} finally {
    unset($service, $db, $disabledService, $disabledDb); gc_collect_cycles();
    foreach (glob($dir . '/*') ?: [] as $file) { if (is_file($file)) { @unlink($file); } }
    @rmdir($dir);
}
