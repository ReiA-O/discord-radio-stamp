<?php
declare(strict_types=1);
require_once __DIR__.'/artifact_directory.php';
require dirname(__DIR__) . '/bootstrap.php';

use RadioStamp\Core\FrozenClock;
use RadioStamp\Discord\AtomicMemberGateway;
use RadioStamp\Discord\ButtonBuilder;
use RadioStamp\Discord\DiscordRestClient as Client;
use RadioStamp\Discord\HttpAdapter;
use RadioStamp\Discord\HttpRequest;
use RadioStamp\Discord\HttpResponse;
use RadioStamp\Discord\RestGateway;
use RadioStamp\Discord\RestStateStore;
use RadioStamp\Http\DiscordRestFollowupSink;

final class RestFakeHttp implements HttpAdapter
{
    public array $requests = [];
    public function __construct(public array $responses = []) {}
    public function exchange(HttpRequest $request): HttpResponse
    {
        $this->requests[] = $request;
        $next = array_shift($this->responses);
        if ($next instanceof Throwable) { throw $next; }
        if (is_callable($next)) { return $next($request); }
        return $next instanceof HttpResponse ? $next : new HttpResponse(0, [], '', 'fake_exhausted');
    }
}
$assertions = 0;
function restCheck(bool $condition, string $message): void { global $assertions; if (!$condition) { throw new RuntimeException('REST test failed: ' . $message); } $assertions++; }
function restJson(array $data, int $status = 200, array $headers = []): HttpResponse { return new HttpResponse($status, $headers, json_encode($data, JSON_THROW_ON_ERROR)); }
function restClient(RestFakeHttp $http, array $options = []): Client { return new Client($http, Client::APPLICATION_ID, 'FICTIONAL_BOT_TOKEN_FOR_MOCK_ONLY', ['enabled' => true] + $options); }
function restRejects(callable $operation): bool { try { $operation(); return false; } catch (Throwable) { return true; } }
function restCommand(array $definition, string $id = '900000000000000123'): array { return $definition + ['id' => $id, 'application_id' => Client::APPLICATION_ID, 'guild_id' => Client::GUILD_ID, 'version' => '900000000000000124']; }

$http = new RestFakeHttp([restJson(['id' => Client::APPLICATION_ID, 'bot' => true, 'email' => 'not-returned', 'token' => 'NEVER_OUTPUT'])]);
$disabled = new Client($http, Client::APPLICATION_ID, 'FICTIONAL_BOT_TOKEN_FOR_MOCK_ONLY');
restCheck(!$disabled->botRead('self_bot')->ok && count($http->requests) === 0, 'transport defaults OFF without even an HTTP request');
$client = restClient($http);
$result = $client->botRead('self_bot');
restCheck($result->ok && $result->data === ['id' => Client::APPLICATION_ID, 'bot' => true], 'self bot is fixed and response secrets projected out');
restCheck($http->requests[0]->method() === 'GET' && $http->requests[0]->url() === 'https://discord.com/api/v10/users/@me', 'fixed official host and GET endpoint');
restCheck(in_array('Authorization: Bot FICTIONAL_BOT_TOKEN_FOR_MOCK_ONLY', $http->requests[0]->headers(), true), 'Bot credential goes only in header');
restCheck(!$client->botRead('channel', '900000000000000099')->ok && !$client->botRead('admin_voice', Client::ADMIN_USER_ID)->ok && !$client->botRead('/arbitrary/path')->ok && count($http->requests) === 1, 'arbitrary path/other channel/scope injection refused before HTTP');
restCheck(restRejects(fn() => new Client($http, '900000000000000001', 'FICTIONAL_BOT_TOKEN_FOR_MOCK_ONLY')), 'different application refused');
restCheck(restRejects(fn() => new Client($http, Client::APPLICATION_ID, "FICTIONAL_BOT_TOKEN\r\nInjected: x")), 'credential header injection refused');
foreach (['http://discord.com/api/v10/users/@me', 'https://discord.com.evil.invalid/api/v10/users/@me', 'https://discord.com:443/api/v10/users/@me', 'https://user@discord.com/api/v10/users/@me', 'https://discord.com/api/v10/users/@me?secret=x', 'https://discord.com/api/v10/users/@me#secret'] as $badUrl) {
    restCheck(restRejects(fn() => new HttpRequest('GET', $badUrl, [])), 'noncanonical host/scheme/userinfo/query/fragment refused');
}
ob_start(); var_dump($client, $http->requests[0]); $debug = ob_get_clean();
restCheck(!str_contains($debug, 'FICTIONAL_BOT_TOKEN_FOR_MOCK_ONLY'), 'object debug output does not reveal credentials');

$identities = new RestFakeHttp([restJson(['id' => Client::APPLICATION_ID, 'bot' => true]), restJson(['id' => Client::APPLICATION_ID, 'bot' => ['id' => Client::APPLICATION_ID], 'flags' => 0, 'verify_key' => 'not-returned']), restJson(['user' => ['id' => Client::APPLICATION_ID], 'roles' => [Client::GUILD_ID], 'pending' => false, 'access_token' => 'not-returned']), restJson(['id' => Client::GUILD_ID, 'owner_id' => Client::ADMIN_USER_ID]), restJson([['id' => Client::GUILD_ID, 'permissions' => '0', 'position' => 0]]), restJson(['id' => Client::TEXT_CHANNEL_ID, 'guild_id' => Client::GUILD_ID, 'type' => 0, 'nsfw' => true, 'parent_id' => '800000000000001004', 'permission_overwrites' => []])]);
$identityClient = restClient($identities);
restCheck(!$identityClient->botRead('bot_member')->ok && count($identities->requests) === 0, 'bot member lookup requires verified self id');
foreach (['self_bot', 'self_application', 'bot_member', 'guild', 'guild_roles'] as $operation) { restCheck($identityClient->botRead($operation)->ok, 'fixed identity/member/roles projection'); }
restCheck($identityClient->botRead('channel', Client::TEXT_CHANNEL_ID)->ok, 'allowlisted channel lookup');
restCheck($identities->requests[2]->url() === 'https://discord.com/api/v10/guilds/' . Client::GUILD_ID . '/members/' . Client::APPLICATION_ID, 'member path uses fixed app bot identity');
restCheck(!restClient(new RestFakeHttp([restJson(['id' => '900000000000000001', 'bot' => true])]))->botRead('self_bot')->ok, 'wrong Bot rejected');
restCheck(!restClient(new RestFakeHttp([restJson(['id' => Client::APPLICATION_ID, 'bot' => ['id' => '900000000000000001']])]))->botRead('self_application')->ok, 'wrong application Bot rejected');

$clock = new FrozenClock('2026-10-17T07:00:00+09:00');
$voice = new RestFakeHttp([restJson(['user_id' => Client::ADMIN_USER_ID, 'channel_id' => Client::VOICE_CHANNEL_ID, 'session_id' => 'not-returned', 'self_mute' => true, 'self_deaf' => true])]);
$gateway = new RestGateway(restClient($voice), $clock);
restCheck(!($gateway instanceof AtomicMemberGateway), 'REST never claims atomic member actions');
restCheck(!$gateway->voiceState(Client::GUILD_ID, '900000000000000099')->known && count($voice->requests) === 0, 'nonowner blocked before HTTP');
restCheck(!$gateway->voiceState('900000000000000099', Client::ADMIN_USER_ID)->known && count($voice->requests) === 0, 'other guild blocked before HTTP');
$state = $gateway->voiceState(Client::GUILD_ID, Client::ADMIN_USER_ID);
restCheck($state->known && $state->channelId === Client::VOICE_CHANNEL_ID, 'speaker-muted and microphone-muted owner still counts as connected');
restCheck(!$gateway->roster(Client::GUILD_ID, Client::VOICE_CHANNEL_ID)->known, 'roster is always UNKNOWN');
restCheck(!$gateway->sendMessage(Client::TEXT_CHANNEL_ID, 'test', 'x') && !$gateway->setRoomOpen(Client::VOICE_CHANNEL_ID, true, 'x') && !$gateway->moveMember(Client::GUILD_ID, Client::ADMIN_USER_ID, Client::TEXT_CHANNEL_ID, 'x') && !$gateway->disconnectMember(Client::GUILD_ID, Client::ADMIN_USER_ID, 'x') && !$gateway->syncDecorationRole(Client::GUILD_ID, Client::ADMIN_USER_ID, null, 'x') && count($voice->requests) === 1, 'all public/room/member/role side effects unconditionally false');
foreach ([new HttpResponse(401, [], '{"token":"never-output"}'), new HttpResponse(403), new HttpResponse(404, [], '{"code":10065}'), new HttpResponse(503), new HttpResponse(302, ['location' => 'https://evil.invalid']), new HttpResponse(0, [], '', 'transport_timeout'), restJson(['user_id' => '900000000000000099', 'channel_id' => Client::VOICE_CHANNEL_ID]), new HttpResponse(200, [], '{broken'), new HttpResponse(200, [], 'null'), new HttpResponse(200, [], str_repeat('x', 1048577))] as $bad) {
    $fake = new RestFakeHttp([$bad]); $unknown = (new RestGateway(restClient($fake), $clock))->voiceState(Client::GUILD_ID, Client::ADMIN_USER_ID);
    restCheck(!$unknown->known && $unknown->channelId === null && count($fake->requests) === 1, 'failure/malformed/unknown voice remains UNKNOWN with one request');
}
$auth = new RestFakeHttp([new HttpResponse(401, [], 'secret')]); $authClient = restClient($auth);
restCheck($authClient->botRead('self_bot')->code === 'unauthorized' && !$authClient->botRead('guild')->ok && count($auth->requests) === 1, '401 stops further requests in same client');
$throws = restClient(new RestFakeHttp([new RuntimeException('FICTIONAL_BOT_TOKEN_FOR_MOCK_ONLY')]));
restCheck($throws->botRead('self_bot')->code === 'transport_failed', 'adapter exception details discarded');

$now = 1000.0; $pdo = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]); $store = new RestStateStore($pdo);
$rate = new RestFakeHttp([new HttpResponse(429, ['Retry-After' => '2'], '{"retry_after":7200,"global":true,"token":"hidden"}'), restJson(['id' => Client::APPLICATION_ID, 'bot' => true])]);
$rateClient = restClient($rate, ['state_store' => $store, 'now' => static function () use (&$now): float { return $now; }]);
$limited = $rateClient->botRead('self_bot');
restCheck($limited->code === 'rate_limited' && $limited->retryAfter === 7200.0 && $limited->data === [], 'longer valid body/header retry interval is honoured in full');
restCheck($rateClient->botRead('guild')->code === 'rate_limited' && count($rate->requests) === 1, 'global cooldown prevents another route');
$now += 3601;
restCheck($rateClient->botRead('self_bot')->code === 'rate_limited' && count($rate->requests) === 1, 'valid two-hour Retry-After is not cut short at one hour');
$now += 3600;
restCheck($rateClient->botRead('self_bot')->ok && count($rate->requests) === 2, 'request resumes after server cooldown only');
$bucketHttp = new RestFakeHttp([restJson(['id' => Client::APPLICATION_ID, 'bot' => true], 200, ['X-RateLimit-Bucket' => 'shared_bucket', 'X-RateLimit-Remaining' => '0', 'X-RateLimit-Reset-After' => '8000'])]);
$bucketClient = restClient($bucketHttp, ['state_store' => $store, 'now' => static function () use (&$now): float { return $now; }]);
restCheck($bucketClient->botRead('self_bot')->ok && $bucketClient->botRead('self_bot')->retryAfter === 3600.0 && count($bucketHttp->requests) === 1, 'successful exhausted route bucket persists its reset interval up to one hour');
$newClient = restClient(new RestFakeHttp(), ['state_store' => $store, 'now' => static function () use (&$now): float { return $now; }]);
restCheck($newClient->botRead('self_bot')->code === 'rate_limited', 'cooldown shared across client instances');
$invalidRate = restClient(new RestFakeHttp([new HttpResponse(429, ['Retry-After' => 'NaN'], '{"retry_after":3}') ]));
$invalidLimited = $invalidRate->botRead('self_bot');
restCheck($invalidLimited->code === 'rate_limited' && $invalidLimited->retryAfter === 3.0, 'malformed header is ignored when the body carries valid guidance');

// Unusable 429 guidance must never become a long or global pause (P1: one bad 429 stopped all REST for 24 hours).
$limitNow = 50000.0; $limitClock = ['now' => static function () use (&$limitNow): float { return $limitNow; }];
$limitStore = static fn(): RestStateStore => new RestStateStore(new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]));
$guildJson = static fn(): HttpResponse => restJson(['id' => Client::GUILD_ID, 'owner_id' => Client::ADMIN_USER_ID]);
$htmlHttp = new RestFakeHttp([new HttpResponse(429, ['Retry-After' => '2'], '<html>slow down</html>'), $guildJson(), restJson(['id' => Client::APPLICATION_ID, 'bot' => true])]);
$htmlClient = restClient($htmlHttp, ['state_store' => $limitStore()] + $limitClock);
$htmlLimited = $htmlClient->botRead('self_bot');
restCheck($htmlLimited->code === 'rate_limited' && $htmlLimited->retryAfter === 2.0, 'HTML 429 uses the valid Retry-After header');
restCheck($htmlClient->botRead('guild')->ok && count($htmlHttp->requests) === 2, 'HTML 429 pauses only its own route, never global');
restCheck($htmlClient->botRead('self_bot')->code === 'rate_limited' && count($htmlHttp->requests) === 2, 'HTML 429 route stays paused for its header interval');
$limitNow += 2.0;
restCheck($htmlClient->botRead('self_bot')->ok && count($htmlHttp->requests) === 3, 'HTML 429 route resumes after two seconds');
$bareHttp = new RestFakeHttp([new HttpResponse(429, [], ''), $guildJson()]);
$bareClient = restClient($bareHttp, ['state_store' => $limitStore()] + $limitClock);
$bareLimited = $bareClient->botRead('self_bot');
restCheck($bareLimited->code === 'rate_limit_invalid' && $bareLimited->retryAfter === 60.0, '429 without body or header uses a sixty second default');
restCheck($bareClient->botRead('guild')->ok && $bareClient->botRead('self_bot')->retryAfter === 60.0 && count($bareHttp->requests) === 2, '429 without guidance is route-only, not global');
$globalHttp = new RestFakeHttp([new HttpResponse(429, [], '{"retry_after":5,"global":true}')]);
$globalClient = restClient($globalHttp, ['state_store' => $limitStore()] + $limitClock);
restCheck($globalClient->botRead('self_bot')->retryAfter === 5.0 && $globalClient->botRead('guild')->code === 'rate_limited' && count($globalHttp->requests) === 1, 'explicit global 429 still pauses every bot route');
$stalePdo = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]); $staleStore = new RestStateStore($stalePdo);
$stalePdo->prepare("INSERT INTO discord_rest_limits(limit_key,until_at) VALUES ('global',?)")->execute([$limitNow + 86400]);
restCheck($staleStore->cooldown('guild', $limitNow) === 0.0, 'far-future limit left by an older release is ignored');
// Advancing the clock must never bring the legacy day-long row back (it used to apply for the final hour after 23 hours).
foreach ([3600, 22 * 3600, 23 * 3600, 23 * 3600 + 1800, 86399, 86400] as $elapsed) {
    restCheck($staleStore->cooldown('guild', $limitNow + $elapsed) === 0.0 && $staleStore->cooldown('self_bot', $limitNow + $elapsed) === 0.0, 'legacy global row stays inert ' . $elapsed . ' s later');
}
$staleBucketPdo = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]); $staleBucketStore = new RestStateStore($staleBucketPdo);
$staleBucketPdo->prepare("INSERT INTO discord_rest_routes(route_key,bucket_key) VALUES ('guild','bucket:legacy')")->execute();
$staleBucketPdo->prepare("INSERT INTO discord_rest_limits(limit_key,until_at) VALUES ('bucket:legacy',?),('route:guild',?)")->execute([$limitNow + 86400, $limitNow + 86400]);
restCheck($staleBucketStore->cooldown('guild', $limitNow + 23 * 3600 + 60) === 0.0, 'legacy bucket and route rows stay inert after 23 hours');
$staleBucketStore->observe('self_bot', 'self_bot', [], 429, 1.0, false, $limitNow);
restCheck((int) $staleBucketPdo->query("SELECT COUNT(*) FROM discord_rest_limits WHERE limit_key NOT LIKE 'v2:%'")->fetchColumn() === 0, 'first write purges every legacy row');
$staleHttp = new RestFakeHttp([new HttpResponse(429, [], '{"retry_after":2,"global":true}'), $guildJson()]);
$staleClient = restClient($staleHttp, ['state_store' => $staleStore] + $limitClock);
restCheck($staleClient->botRead('guild')->retryAfter === 2.0 && $staleStore->cooldown('self_bot', $limitNow) === 2.0, 'new global limit replaces the stale far-future row');
restCheck((float) $stalePdo->query("SELECT until_at FROM discord_rest_limits WHERE limit_key='v2:global'")->fetchColumn() === $limitNow + 2.0 && (int) $stalePdo->query("SELECT COUNT(*) FROM discord_rest_limits WHERE limit_key='global'")->fetchColumn() === 0, 'new bounded global row written and the legacy row purged');
$limitNow += 2.0;
restCheck($staleClient->botRead('guild')->ok && count($staleHttp->requests) === 2, 'requests resume after the replaced global limit');
$capPdo = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]); $capStore = new RestStateStore($capPdo);
$capStore->observe('guild', 'guild', ['x-ratelimit-remaining' => '0', 'x-ratelimit-reset-after' => '86400'], 200, 1.0, false, $limitNow);
$capStore->observe('guild', 'guild', [], 429, INF, true, $limitNow);
restCheck($capStore->cooldown('guild', $limitNow) === 3600.0 && (float) $capPdo->query('SELECT MAX(until_at) FROM discord_rest_limits')->fetchColumn() === $limitNow + 3600.0, 'bucket resets and non-finite input never exceed one hour');
// A valid long Retry-After is honoured in full: nothing is sent before Discord's interval ends.
$longStore = $limitStore();
$longStore->observe('guild', 'guild', [], 429, 7200.0, false, $limitNow);
restCheck($longStore->cooldown('guild', $limitNow) === 7200.0 && $longStore->cooldown('guild', $limitNow + 7199.0) === 1.0 && $longStore->cooldown('guild', $limitNow + 7200.0) === 0.0, 'valid two-hour Retry-After honoured exactly, then released');
$longStore->observe('self_bot', 'self_bot', [], 429, 200000.0, true, $limitNow);
restCheck($longStore->cooldown('guild', $limitNow) === 200000.0 && $longStore->cooldown('guild', $limitNow + 86400.0) === 113600.0 && $longStore->cooldown('guild', $limitNow + 199999.0) === 1.0 && $longStore->cooldown('guild', $limitNow + 200000.0) === 0.0, 'valid Retry-After beyond a day is honoured in full');
$longHttp = new RestFakeHttp([new HttpResponse(429, [], '{"retry_after":200000,"global":true}'), $guildJson()]);
$longClient = restClient($longHttp, ['state_store' => $limitStore()] + $limitClock);
restCheck($longClient->botRead('self_bot')->retryAfter === 200000.0, 'client reports the full long Retry-After');
$longStart = $limitNow;
foreach ([86400.0, 86401.0, 199999.0] as $elapsed) {
    $limitNow = $longStart + $elapsed;
    restCheck($longClient->botRead('guild')->code === 'rate_limited' && count($longHttp->requests) === 1, 'no request ' . $elapsed . ' s into a 200000 s Retry-After');
}
$limitNow = $longStart + 200000.0;
restCheck($longClient->botRead('guild')->ok && count($longHttp->requests) === 2, 'request resumes when the full Retry-After ends');
$limitNow = $longStart;
// A clock stepped back keeps the full observed interval from now, never more; a forward step cannot be told apart and ends it.
$stepStore = $limitStore(); $stepStore->observe('guild', 'guild', [], 429, 100.0, false, $limitNow);
restCheck($stepStore->cooldown('guild', $limitNow - 1000.0) === 100.0 && $stepStore->cooldown('guild', $limitNow + 40.0) === 60.0, 'clock stepped back re-bases to the observed interval');
$stepStore->observe('guild', 'guild', [], 429, 10.0, false, $limitNow - 1000.0);
restCheck($stepStore->cooldown('guild', $limitNow - 1000.0) === 100.0 && $stepStore->cooldown('guild', $limitNow - 901.0) === 1.0 && $stepStore->cooldown('guild', $limitNow - 900.0) === 0.0, 'write after a backward step keeps the longer re-based wait');
// Rows from the previous release (prefixed, no seen time) stay valid within their one-day horizon only.
$prevPdo = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]); $prevStore = new RestStateStore($prevPdo);
$prevPdo->prepare("INSERT INTO discord_rest_limits(limit_key,until_at) VALUES ('v2:global',?),('v2:route:guild',?)")->execute([$limitNow + 600.0, $limitNow + 90000.0]);
restCheck($prevStore->cooldown('self_bot', $limitNow) === 600.0 && $prevStore->cooldown('guild', $limitNow) === 600.0, 'previous-release row within a day still applies; one beyond a day is ignored');
$prevStore->observe('guild', 'guild', [], 429, 5.0, false, $limitNow);
restCheck($prevStore->cooldown('guild', $limitNow) === 600.0 && (float) $prevPdo->query("SELECT until_at FROM discord_rest_limits WHERE limit_key='v2:route:guild'")->fetchColumn() === $limitNow + 5.0, 'previous-release row beyond a day is replaced on write');
$resetStore = $limitStore(); $resetStore->observe('missing_reset', 'missing_reset', ['x-ratelimit-remaining' => '0'], 200, 1.0, false, $limitNow);
restCheck($resetStore->cooldown('missing_reset', $limitNow) === 5.0, 'exhausted bucket without reset-after waits five seconds, not a day');
$interactionStore = $limitStore(); $interactionToken = 'FICTIONAL_INTERACTION_TOKEN_FOR_MOCK_ONLY'; $interactionId = '900000000000000401';
$interactionHttp = new RestFakeHttp([new HttpResponse(429, [], '{"retry_after":30,"global":true}'), restJson(['id' => $interactionId]), new HttpResponse(429, [], '{"retry_after":60,"global":true}'), $guildJson()]);
$interactionClient = restClient($interactionHttp, ['state_store' => $interactionStore] + $limitClock);
restCheck($interactionClient->botRead('guild')->code === 'rate_limited' && $interactionStore->cooldown('guild', $limitNow) === 30.0, 'bot global limit recorded');
restCheck($interactionStore->cooldown('interaction_edit', $limitNow, $interactionToken) === 0.0 && $interactionClient->editOriginal($interactionId, $interactionToken, ['content' => 'x'])->ok && count($interactionHttp->requests) === 2, 'interaction_edit ignores the bot global cooldown');
$interactionLimited = $interactionClient->editOriginal($interactionId, $interactionToken, ['content' => 'x']);
restCheck($interactionLimited->code === 'rate_limited' && $interactionStore->cooldown('interaction_edit', $limitNow, $interactionToken) === 60.0, 'interaction_edit 429 pauses its own token');
restCheck($interactionStore->cooldown('interaction_edit', $limitNow, 'FICTIONAL_OTHER_INTERACTION_TOKEN_MOCK') === 0.0, 'another interaction token is not paused');
$tokenStore = $limitStore(); $tokenStore->observe('interaction_edit', 'TOKEN_A', [], 429, 7200.0, true, $limitNow);
restCheck($tokenStore->cooldown('interaction_edit', $limitNow, 'TOKEN_A') === 7200.0 && $tokenStore->cooldown('interaction_edit', $limitNow + 900.0, 'TOKEN_A') === 6300.0 && $tokenStore->cooldown('interaction_edit', $limitNow + 7200.0, 'TOKEN_A') === 0.0 && $tokenStore->cooldown('guild', $limitNow) === 0.0, 'long interaction Retry-After is honoured in full for its token and never global');
restCheck($tokenStore->cooldown('interaction_edit', $limitNow, 'TOKEN_B') === 0.0, 'one token\'s long Retry-After never holds back other interactions');
$tokenStore->observe('interaction_edit', 'TOKEN_A', ['x-ratelimit-bucket' => 'webhook_bucket', 'x-ratelimit-remaining' => '0', 'x-ratelimit-reset-after' => '30'], 200, 1.0, false, $limitNow);
restCheck($tokenStore->cooldown('interaction_edit', $limitNow, 'TOKEN_B') === 0.0, 'an exhausted bucket on one token never pauses another token');
$limitNow += 30.0;
restCheck($interactionClient->botRead('guild')->ok && count($interactionHttp->requests) === 4, 'interaction 429 marked global never writes the bot global limit');
// Discord limits interaction webhooks per token: a long Retry-After for one user's reply never blocks another user's reply.
$otherToken = 'FICTIONAL_SECOND_INTERACTION_TOKEN_MOCK';
$perTokenPdo = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]); $perTokenStore = new RestStateStore($perTokenPdo);
$perTokenHttp = new RestFakeHttp([new HttpResponse(429, [], '{"retry_after":7200}'), restJson(['id' => $interactionId])]);
$perTokenClient = restClient($perTokenHttp, ['state_store' => $perTokenStore] + $limitClock);
restCheck($perTokenClient->editOriginal($interactionId, $interactionToken, ['content' => 'x'])->retryAfter === 7200.0, 'interaction 429 reports its full Retry-After');
restCheck($perTokenClient->editOriginal($interactionId, $otherToken, ['content' => 'x'])->ok && count($perTokenHttp->requests) === 2, 'another token still sends during the first token\'s Retry-After');
restCheck($perTokenClient->editOriginal($interactionId, $interactionToken, ['content' => 'x'])->code === 'rate_limited' && count($perTokenHttp->requests) === 2, 'the limited token sends nothing during its Retry-After');
restCheck((int) $perTokenPdo->query("SELECT COUNT(*) FROM discord_rest_limits WHERE instr(limit_key, 'FICTIONAL') > 0")->fetchColumn() === 0 && (int) $perTokenPdo->query('SELECT COUNT(*) FROM discord_rest_routes')->fetchColumn() === 0, 'only a hash of the token is stored');
$perTokenStore->observe('guild', 'guild', [], 429, 1.0, false, $limitNow + 7200.0);
restCheck((int) $perTokenPdo->query("SELECT COUNT(*) FROM discord_rest_limits WHERE limit_key LIKE '%interaction%'")->fetchColumn() === 0, 'expired token rows and their seen rows are purged on the next write');
// A finite Retry-After beyond a week is malformed: not adopted, only its route pauses for sixty seconds, never global.
$hugeStore = $limitStore(); $hugeStore->observe('self_bot', 'self_bot', [], 429, 1e9, true, $limitNow);
restCheck($hugeStore->cooldown('guild', $limitNow) === 0.0 && $hugeStore->cooldown('self_bot', $limitNow) === 60.0 && $hugeStore->cooldown('self_bot', $limitNow + 60.0) === 0.0, 'huge finite Retry-After pauses only its route for sixty seconds');
$weekStore = $limitStore(); $weekStore->observe('self_bot', 'self_bot', [], 429, 604800.0, true, $limitNow); $weekStore->observe('guild', 'guild', [], 429, 604801.0, false, $limitNow);
restCheck($weekStore->cooldown('member', $limitNow + 604799.0) === 1.0 && $weekStore->cooldown('member', $limitNow + 604800.0) === 0.0 && $weekStore->cooldown('guild', $limitNow + 604799.0) === 1.0, 'exactly a week is honoured in full');
$overStore = $limitStore(); $overStore->observe('guild', 'guild', [], 429, 604801.0, false, $limitNow);
restCheck($overStore->cooldown('guild', $limitNow) === 60.0 && $overStore->cooldown('guild', $limitNow + 60.0) === 0.0, 'one second beyond a week is malformed');
$hugeHttp = new RestFakeHttp([new HttpResponse(429, ['Retry-After' => '1000000000'], '{"retry_after":1000000000,"global":true}'), $guildJson()]);
$hugeClient = restClient($hugeHttp, ['state_store' => $limitStore()] + $limitClock);
$hugeLimited = $hugeClient->botRead('self_bot');
restCheck($hugeLimited->code === 'rate_limit_invalid' && $hugeLimited->retryAfter === 60.0, 'client treats a huge Retry-After as invalid guidance');
restCheck($hugeClient->botRead('guild')->ok && $hugeClient->botRead('self_bot')->code === 'rate_limited' && count($hugeHttp->requests) === 2, 'huge global 429 never stops other routes');

// A slow response must never consume its own relative cooldown while in flight.
foreach (['retry_after', 'reset_after'] as $case) {
    $receiveNow = 20000.0;
    $delayedRateHttp = new RestFakeHttp([
        static function () use (&$receiveNow, $case): HttpResponse {
            $receiveNow += 5.0;
            return $case === 'retry_after'
                ? new HttpResponse(429, ['Retry-After' => '2'], '{"retry_after":2}')
                : restJson(['id' => Client::APPLICATION_ID, 'bot' => true], 200, ['X-RateLimit-Bucket' => 'delayed_bucket', 'X-RateLimit-Remaining' => '0', 'X-RateLimit-Reset-After' => '2']);
        },
        restJson(['id' => Client::APPLICATION_ID, 'bot' => true]),
    ]);
    $delayedRateClient = restClient($delayedRateHttp, ['now' => static function () use (&$receiveNow): float { return $receiveNow; }]);
    $first = $delayedRateClient->botRead('self_bot');
    restCheck(($case === 'retry_after' ? !$first->ok : $first->ok) && count($delayedRateHttp->requests) === 1, 'slow rate response received after fake clock advances five seconds');
    restCheck($delayedRateClient->botRead('self_bot')->retryAfter === 2.0 && count($delayedRateHttp->requests) === 1, 'full relative wait starts after response receipt');
    $receiveNow += 1.999;
    restCheck($delayedRateClient->botRead('self_bot')->code === 'rate_limited' && count($delayedRateHttp->requests) === 1, 'less than two seconds after receipt still cannot resend');
    $receiveNow += 0.002;
    restCheck($delayedRateClient->botRead('self_bot')->ok && count($delayedRateHttp->requests) === 2, 'request resumes only after received-relative cooldown expires');
}

$callback = 'FICTIONAL_CALLBACK_TOKEN_FOR_MOCK_ONLY'; $originalId = '900000000000000301';
$followHttp = new RestFakeHttp([restJson(['id' => $originalId, 'token' => 'not-returned']), restJson(['id' => $originalId])]);
$followClient = restClient($followHttp); $sink = new DiscordRestFollowupSink($followClient);
restCheck($sink->send($originalId, $callback, ['ok' => true, 'code' => 'attended'], null), 'ephemeral original edit success');
restCheck($sink->send($originalId, $callback, ['ok' => true, 'code' => 'card_ready'], "\x89PNG\r\n\x1a\nMOCK_ONLY"), 'bounded PNG multipart original edit');
foreach ($followHttp->requests as $request) {
    restCheck($request->method() === 'PATCH' && $request->url() === 'https://discord.com/api/v10/webhooks/' . Client::APPLICATION_ID . '/' . $callback . '/messages/@original', 'retries edit same original, never POST a duplicate');
    restCheck(count(array_filter($request->headers(), static fn(string $header): bool => str_starts_with($header, 'Authorization:'))) === 0, 'callback edit does not attach Bot credential');
    restCheck(str_contains($request->body(), '"parse":[]') && str_contains($request->body(), '"users":[]'), 'all mentions hard disabled');
}
restCheck(str_contains($followHttp->requests[1]->body(), 'name="files[0]"; filename="card.png"') && str_contains($followHttp->requests[1]->body(), '"attachments":[{"id":0'), 'multipart contains explicit attachment replacement');
restCheck(!$followClient->editOriginal($originalId, $callback, ['content' => 'x', 'embeds' => []])->ok && !$followClient->editOriginal($originalId, '../' . $callback, ['content' => 'x'])->ok && !$followClient->editOriginal($originalId, $callback, ['content' => 'x'], str_repeat('x', 1048577))->ok && count($followHttp->requests) === 2, 'payload/token/image injection rejected without network');
restCheck(!$followClient->editOriginal($originalId, $callback, ['content' => 'x', 'components' => [['type' => 1, 'components' => [['type' => 2, 'style' => 5, 'url' => 'https://evil.invalid', 'label' => 'x']]]]])->ok, 'URL buttons rejected');
restCheck(!$followClient->editOriginal($originalId, 'FICTIONAL_BOT_TOKEN_FOR_MOCK_ONLY', ['content' => 'x'])->ok && count($followHttp->requests) === 2, 'Bot credential can never become callback URL credential');
$callbackFailureHttp = new RestFakeHttp([new HttpResponse(401), restJson(['id' => Client::APPLICATION_ID, 'bot' => true])]); $callbackFailureClient = restClient($callbackFailureHttp);
restCheck(!$callbackFailureClient->editOriginal($originalId, $callback, ['content' => 'x'])->ok && $callbackFailureClient->botRead('self_bot')->ok && count($callbackFailureHttp->requests) === 2, 'one expired callback does not poison Bot identity reads');
$botFailureHttp = new RestFakeHttp([new HttpResponse(401), restJson(['id' => $originalId])]); $botFailureClient = restClient($botFailureHttp);
restCheck(!$botFailureClient->botRead('self_bot')->ok && $botFailureClient->editOriginal($originalId, $callback, ['content' => 'x'])->ok && count($botFailureHttp->requests) === 2, 'Bot authentication failure does not prevent valid callback error response');

$controlHttp = new RestFakeHttp(array_fill(0, 6, restJson(['id' => $originalId])));
$controlClient = restClient($controlHttp);
foreach (['0000', '0400', '0700', '2200'] as $slot) {
    restCheck($controlClient->editOriginal($originalId, $callback, ['content' => '本人用の検証ボタン', 'components' => ButtonBuilder::sessionControls('2028-02-29_' . $slot)])->ok, 'fixed owner controls accept valid leap date and all supported slots');
}
$monthlyControl = [['type' => 1, 'components' => [['type' => 2, 'style' => 2, 'label' => 'カード表示', 'custom_id' => 'radio:card:2026-10']]]];
restCheck($controlClient->editOriginal($originalId, $callback, ['content' => '本人用カード', 'components' => $monthlyControl])->ok, 'standalone fixed monthly card control allowed');
$memorialControls = array_map(static fn(array $row): array => ['type' => 1, 'components' => $row], array_chunk(array_map(static fn(int $threshold): array => ['type' => 2, 'style' => 2, 'label' => $threshold . '日 仮カード', 'custom_id' => 'radio:memorial:' . $threshold], [10, 30, 60, 100, 300, 365]), 5));
restCheck($controlClient->editOriginal($originalId, $callback, ['content' => '仮カード', 'components' => $memorialControls])->ok, 'six day milestones fit two rows with at most five buttons');
$validControls = ButtonBuilder::sessionControls('2026-10-17_0700');
$badControls = [];
foreach (['radio:start:2026-02-29_0700', 'radio:start:2026-02-30_0700', 'radio:start:2026-00-17_0700', 'radio:start:2026-10-00_0700', 'radio:start:1999-10-17_0700', 'radio:start:2026-10-17_0715', 'radio:delete:2026-10-17_0700', 'radio:start:../../config.php'] as $badId) {
    $bad = $validControls; $bad[0]['components'][0]['custom_id'] = $badId; $badControls[] = $bad;
}
$bad = $validControls; $bad[0]['components'][0]['style'] = 2; $badControls[] = $bad;
$bad = $validControls; $bad[0]['components'][1]['style'] = 1; $badControls[] = $bad;
$bad = $validControls; $bad[0]['components'][2]['style'] = 3; $badControls[] = $bad;
$bad = $validControls; $bad[0]['components'][0]['label'] = '別の表示名'; $badControls[] = $bad;
$bad = $validControls; $bad[0]['components'][1]['custom_id'] = 'radio:attend:2026-10-18_0700'; $badControls[] = $bad;
$bad = $validControls; $bad[0]['components'][2]['custom_id'] = 'radio:card:2026-11'; $badControls[] = $bad;
$bad = $validControls; $bad[0]['components'][2]['custom_id'] = 'radio:card:2026-13'; $badControls[] = $bad;
$bad = $validControls; $bad[0]['components'][0]['url'] = 'https://evil.invalid'; $badControls[] = $bad;
$bad = $validControls; $bad[0]['components'][0]['style'] = 5; $badControls[] = $bad;
$bad = $validControls; $bad[0]['components'][1] = $bad[0]['components'][0]; $badControls[] = $bad;
$bad = $validControls; $bad[0]['components'][] = $memorialControls[0]['components'][0]; $badControls[] = $bad;
$badControls[] = [$memorialControls[0], $validControls[0]];
$badControls[] = [['type' => 1, 'components' => []]];
$bad = $memorialControls; $bad[1]['components'][0] = $bad[0]['components'][0]; $badControls[] = $bad;
$bad = $memorialControls; $bad[0]['components'][0]['label'] = '1回 記念カード'; $badControls[] = $bad;
$badControls[] = [['type'=>1,'components'=>[['type'=>2,'style'=>2,'label'=>'1日 仮カード','custom_id'=>'radio:memorial:1']]]];
foreach ($badControls as $bad) { restCheck(!$controlClient->editOriginal($originalId, $callback, ['content' => 'x', 'components' => $bad])->ok, 'invalid calendar/style/label/session/URL/unknown/duplicate/shape control refused'); }
restCheck(count($controlHttp->requests) === 6, 'all invalid controls rejected without any additional request');
$failureSinkHttp = new RestFakeHttp([restJson(['id' => $originalId]), restJson(['id' => $originalId])]);
$failureSink = new DiscordRestFollowupSink(restClient($failureSinkHttp));
restCheck($failureSink->send($originalId, $callback, ['ok' => false, 'code' => 'validation_bot_identity_unverified'], null) && $failureSink->send($originalId, $callback, ['ok' => false, 'code' => 'bot_check_channel_failed'], null), 'identity and stage-specific safe failure messages can edit defer');
$failureMessage = json_decode($failureSinkHttp->requests[0]->body(), true, 16, JSON_THROW_ON_ERROR);
restCheck(str_contains($failureMessage['content'], '対象Botの本人確認に失敗') && $failureMessage['components'] === [], 'identity failure gives fixed actionable message without controls');

$defs = Client::validationCommandDefinitions();
$commandOptions = ['command_registration_enabled' => true, 'connection_ready' => true];
$statusOnlyHttp = new RestFakeHttp([restJson([]), restJson(restCommand($defs[2]), 201)]);
restCheck(restClient($statusOnlyHttp, $commandOptions)->upsertValidationCommands(['radio-status'])->ok && count($statusOnlyHttp->requests) === 2, 'explicit status-only selection makes one GET and one POST');
restCheck(json_decode($statusOnlyHttp->requests[1]->body(), true, 32, JSON_THROW_ON_ERROR)['name'] === 'radio-status', 'status-only registration never writes another definition');
$badNamesHttp = new RestFakeHttp(); $badNamesClient = restClient($badNamesHttp, $commandOptions);
foreach ([[], ['arbitrary-command'], ['radio-status', 'radio-status'], [1]] as $badNames) { restCheck($badNamesClient->upsertValidationCommands($badNames)->code === 'command_scope_rejected' && count($badNamesHttp->requests) === 0, 'unknown, duplicate, empty or nonstring command selection rejected before HTTP'); }
restCheck(restClient(new RestFakeHttp())->upsertValidationCommands()->code === 'command_registration_disabled', 'command registration defaults OFF');
restCheck(restClient(new RestFakeHttp(), ['command_registration_enabled' => true])->upsertValidationCommands()->code === 'command_registration_disabled', 'connection readiness independently required');
$conflict = $defs[1]; $conflict['description'] = 'different existing command';
$conflictHttp = new RestFakeHttp([restJson([restCommand($conflict)])]);
restCheck(restClient($conflictHttp, $commandOptions)->upsertValidationCommands()->code === 'command_name_conflict' && count($conflictHttp->requests) === 1, 'any conflicting existing name stops all writes');
$sameHttp = new RestFakeHttp([restJson([restCommand($defs[0]), restCommand($defs[1]), restCommand($defs[2])])]);
restCheck(restClient($sameHttp, $commandOptions)->upsertValidationCommands()->ok && count($sameHttp->requests) === 1, 'same fixed definition ignores only response extra metadata and performs no POST');
$wrongOrder = $defs[1]; $wrongOrder['options'][0]['required'] = 0;
restCheck(restClient(new RestFakeHttp([restJson([restCommand($wrongOrder)])]), $commandOptions)->upsertValidationCommands()->code === 'command_name_conflict', 'options types compared strictly');
$commandPdo = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]); $commandStore = new RestStateStore($commandPdo);
$commandHttp = new RestFakeHttp([restJson([]), restJson(restCommand($defs[0]), 201), restJson(restCommand($defs[1]), 201), restJson(restCommand($defs[2]), 201), restJson([restCommand($defs[0]), restCommand($defs[1]), restCommand($defs[2])])]);
$commandClient = restClient($commandHttp, $commandOptions + ['state_store' => $commandStore]);
restCheck($commandClient->upsertValidationCommands()->ok && $commandClient->upsertValidationCommands()->ok && count($commandHttp->requests) === 5, 'three new commands POST once each and repeat preflight does not duplicate');
foreach ($commandHttp->requests as $request) { restCheck(str_contains($request->url(), '/applications/' . Client::APPLICATION_ID . '/guilds/' . Client::GUILD_ID . '/commands') && in_array($request->method(), ['GET', 'POST'], true), 'commands are fixed guild only; no bulk/global/DELETE/PUT'); }
restCheck((int) $commandPdo->query("SELECT COUNT(*) FROM discord_rest_commands WHERE state='done'")->fetchColumn() === 3, 'three per-command revision successes persisted');
restCheck(!str_contains(json_encode($commandPdo->query('SELECT * FROM discord_rest_commands')->fetchAll()), $callback), 'command state contains no token');
$missingHttp = new RestFakeHttp([restJson([])]);
restCheck(restClient($missingHttp, $commandOptions + ['state_store' => $commandStore])->upsertValidationCommands()->code === 'command_missing_after_registration' && count($missingHttp->requests) === 1, 'missing command with stale completed state fails without recreating or claiming success');
$attemptPdo = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]); $attemptStore = new RestStateStore($attemptPdo); $attemptNow = 10000.0;
$attemptHttp = new RestFakeHttp([restJson([]), new HttpResponse(0, [], '', 'transport_timeout'), restJson([]), new HttpResponse(0, [], '', 'transport_timeout'), restJson([]), new HttpResponse(0, [], '', 'transport_timeout'), restJson([])]);
$attemptClient = restClient($attemptHttp, $commandOptions + ['state_store' => $attemptStore, 'now' => static function () use (&$attemptNow): float { return $attemptNow; }]);
for ($i = 0; $i < 3; $i++) { restCheck(!$attemptClient->upsertValidationCommands()->ok, 'uncertain POST retry remains bounded'); $attemptNow += 30; }
restCheck($attemptClient->upsertValidationCommands()->code === 'command_attempts_exhausted' && count(array_filter($attemptHttp->requests, static fn(HttpRequest $r): bool => $r->method() === 'POST')) === 3, 'three command HTTP attempts maximum across persisted state');
$slowCommandPdo = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$slowCommandStore = new RestStateStore($slowCommandPdo); $slowCommandNow = 30000.0;
$slowCommandHttp = new RestFakeHttp([restJson([]), static function () use (&$slowCommandNow): HttpResponse { $slowCommandNow += 5.0; return new HttpResponse(0, [], '', 'transport_timeout'); }, restJson([]), restJson([])]);
$slowCommandClient = restClient($slowCommandHttp, $commandOptions + ['state_store' => $slowCommandStore, 'now' => static function () use (&$slowCommandNow): float { return $slowCommandNow; }]);
restCheck(!$slowCommandClient->upsertValidationCommands()->ok && (float) $slowCommandPdo->query('SELECT retry_at FROM discord_rest_commands')->fetchColumn() === 30007.0, 'command retry backoff begins after slow POST completes');
restCheck($slowCommandClient->upsertValidationCommands()->code === 'command_pending', 'command cannot retry immediately after slow response');
$slowCommandNow += 1.999;
restCheck($slowCommandClient->upsertValidationCommands()->code === 'command_pending' && count(array_filter($slowCommandHttp->requests, static fn(HttpRequest $r): bool => $r->method() === 'POST')) === 1, 'slow command waits full two seconds before another POST');

$inlineHttp = new RestFakeHttp([restJson(['id' => Client::APPLICATION_ID, 'bot' => true])]);
$inline = restClient($inlineHttp, ['inline_self_bot_only' => true, 'timeout_ms' => 900, 'connect_timeout_ms' => 300, 'deadline_monotonic' => hrtime(true) / 1e9 + 1.7]);
restCheck(!$inline->botRead('guild')->ok && !$inline->editOriginal($originalId, $callback, ['content' => 'x'])->ok && !$inline->upsertValidationCommands()->ok && count($inlineHttp->requests) === 0, 'inline fallback forbids every operation except self_bot');
restCheck($inline->botRead('self_bot')->ok && !$inline->botRead('self_bot')->ok && count($inlineHttp->requests) === 1, 'inline fallback sends exactly one GET and never retries');
restCheck($inlineHttp->requests[0]->timeoutMs <= 900 && $inlineHttp->requests[0]->connectTimeoutMs <= 300 && $inlineHttp->requests[0]->asyncDnsRequired, 'inline request enforces DNS capability and short deadlines');
$lateHttp = new RestFakeHttp(); $late = restClient($lateHttp, ['inline_self_bot_only' => true, 'deadline_monotonic' => hrtime(true) / 1e9 - 1]);
restCheck($late->botRead('self_bot')->code === 'operation_deadline' && count($lateHttp->requests) === 0, 'elapsed total deadline prevents network');
$delayedHttp = new RestFakeHttp([static function (): HttpResponse { usleep(350000); return restJson(['id' => Client::APPLICATION_ID, 'bot' => true]); }]);
$delayed = restClient($delayedHttp, ['inline_self_bot_only' => true, 'deadline_monotonic' => hrtime(true) / 1e9 + 0.3]);
restCheck($delayed->botRead('self_bot')->code === 'operation_deadline' && count($delayedHttp->requests) === 1, 'late response cannot report inline connection success');
// Concurrent workers: once a limit has run out, only one may probe; the others wait for its answer.
$probeFile = tempnam(sys_get_temp_dir(), 'rest-probe-');
$probePdo = static fn(): PDO => (static function (PDO $pdo): PDO { $pdo->exec('PRAGMA busy_timeout=2000'); return $pdo; })(new PDO('sqlite:' . $probeFile, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]));
$workerA = new RestStateStore($probePdo()); $workerB = new RestStateStore($probePdo());
restCheck($workerA->reserve('guild', $limitNow) === [0.0, []], 'no limit row: no probe and no lock');
$workerA->observe('guild', 'guild', [], 429, 30.0, true, $limitNow);
[$waitB] = $workerB->reserve('guild', $limitNow + 10.0);
restCheck($waitB === 20.0, 'another worker sees the active global limit');
[$waitA, $probeA] = $workerA->reserve('guild', $limitNow + 30.0);
restCheck($waitA === 0.0 && $probeA !== [], 'first worker after the limit takes the probe');
[$waitB, $probeB] = $workerB->reserve('guild', $limitNow + 30.5);
restCheck($waitB > 0.0 && $waitB <= 15.0 && $probeB === [], 'second worker waits while the probe is out');
[$waitOther, $probeOther] = $workerB->reserve('channel', $limitNow + 30.5);
restCheck($waitOther > 0.0 && $probeOther === [], 'the spent global limit also holds other bot routes behind the probe');
restCheck($workerA->reserve('interaction_edit', $limitNow + 30.5, 'FICTIONAL_INTERACTION_TOKEN_FOR_MOCK_ONLY') === [0.0, []], 'interaction replies are not held by the bot global probe');
$workerA->observe('guild', 'guild', [], 429, 40.0, true, $limitNow + 31.0); $workerA->release($probeA, false, $limitNow + 31.0);
[$waitB, $probeB] = $workerB->reserve('guild', $limitNow + 32.0);
restCheck($waitB === 39.0 && $probeB === [], 'probe 429 sets the next limit for every worker');
[$waitA, $probeA] = $workerA->reserve('guild', $limitNow + 71.0);
restCheck($waitA === 0.0 && $probeA !== [], 'next probe after the renewed limit');
$workerA->release($probeA, true, $limitNow + 71.5);
restCheck($workerB->reserve('guild', $limitNow + 72.0) === [0.0, []] && $workerB->reserve('channel', $limitNow + 72.0) === [0.0, []], 'probe 2xx clears spent rows so later requests need no probe');
$workerA->observe('guild', 'guild', [], 429, 5.0, false, $limitNow + 80.0);
[, $abandoned] = $workerA->reserve('guild', $limitNow + 85.0);
[$waitB, $probeB] = $workerB->reserve('guild', $limitNow + 85.0 + 15.0);
restCheck($abandoned !== [] && $waitB === 0.0 && $probeB !== [], 'a probe never handed back expires after its hold');
$workerB->release($probeB, false, $limitNow + 100.0);
// A request sent before the limit existed answers 429 (two days) while a probe is out; the probe's 2xx must not drop that limit's seen row.
[, $probeA] = $workerA->reserve('guild', $limitNow + 100.5);
$workerB->observe('guild', 'guild', [], 429, 172800.0, false, $limitNow + 101.0);
$workerA->release($probeA, true, $limitNow + 101.5);
restCheck($workerB->cooldown('guild', $limitNow + 102.0) === 172799.0, 'a long 429 that lands while a probe is out survives the probe 2xx');
unset($workerA, $workerB); @unlink($probeFile);
$probeHttp = new RestFakeHttp([new HttpResponse(429, ['Retry-After' => '30'], '{"retry_after":30,"global":true}'), restJson(['id' => Client::APPLICATION_ID, 'bot' => true]), restJson(['id' => Client::APPLICATION_ID, 'bot' => true])]);
$probeClientStore = $limitStore(); $probeClient = restClient($probeHttp, ['state_store' => $probeClientStore] + $limitClock);
$probeClient->botRead('self_bot'); $limitNow += 30.0;
restCheck($probeClient->botRead('self_bot')->ok && $probeClientStore->reserve('guild', $limitNow) === [0.0, []] && count($probeHttp->requests) === 2, 'client hands its probe back and a 2xx clears the spent limit');
echo "REST: {$assertions} assertions passed (fake HTTP only; no live calls).\n";
