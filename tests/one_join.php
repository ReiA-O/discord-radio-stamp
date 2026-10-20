<?php
declare(strict_types=1);
require_once __DIR__.'/artifact_directory.php';
require dirname(__DIR__) . '/bootstrap.php';

use RadioStamp\Core\FrozenClock;
use RadioStamp\Discord\HttpAdapter;
use RadioStamp\Discord\HttpRequest;
use RadioStamp\Discord\HttpResponse;
use RadioStamp\Discord\PresenceDiagnostic;
use RadioStamp\Discord\RestResult;
use RadioStamp\Http\PostResponseDriver;
use RadioStamp\Http\PrivateConfig as Scope;
use RadioStamp\Http\Response;
use RadioStamp\Validation\OneJoin\Endpoint;
use RadioStamp\Validation\OneJoin\GuardedHttp;
use RadioStamp\Validation\OneJoin\Publisher;
use RadioStamp\Validation\OneJoin\Store;
use RadioStamp\Validation\OneJoin\Trial;
use RadioStamp\Validation\OneJoin\Window;

$oneJoinAssertions = 0;
function ojCheck(bool $value, string $description): void { global $oneJoinAssertions; ++$oneJoinAssertions; if (!$value) { throw new RuntimeException('One-join assertion failed: ' . $description); } }
function ojSame(mixed $expected, mixed $actual, string $description): void { ojCheck($expected === $actual, $description); }

final class OjEvents { public bool $finished = false; public array $items = []; }
final class OjDriver implements PostResponseDriver
{
    public bool $supported = true; public bool $finishes = true; public bool $throws = false;
    public function __construct(public OjEvents $events) {}
    public function available(): bool { return $this->supported; }
    public function finish(): bool { $this->events->items[] = 'finish'; if ($this->throws) { throw new RuntimeException('FICTIONAL_FINISH_EXCEPTION'); } $this->events->finished = $this->finishes; return $this->finishes; }
}
final class OjHttp implements HttpAdapter
{
    public array $calls = []; public string $presence = 'inside'; public string $failure = ''; public ?Closure $hook = null;
    public const MESSAGE = '900000000000009001';
    public function __construct(private OjEvents $events) {}
    public function exchange(HttpRequest $request): HttpResponse
    {
        ojCheck($this->events->finished, 'every HTTP exchange follows formal finish');
        $path = (string)parse_url($request->url(), PHP_URL_PATH); $method = $request->method();
        $this->events->items[] = $method;
        $safePath = str_starts_with($path, '/api/v10/webhooks/') ? '/webhooks/fixed/[redacted]/messages/@original' : $path;
        $this->calls[] = ['method' => $method, 'path' => $safePath];
        if ($this->hook !== null) { ($this->hook)($method, $path); }
        if ($method === 'PATCH') {
            ojCheck(!array_filter($request->headers(), static fn(string $h): bool => str_starts_with($h, 'Authorization:')), 'ephemeral PATCH uses no Bot header');
            $body = $request->body();
            ojCheck(str_contains($body, '"allowed_mentions":{"parse":[],"users":[],"roles":[],"replied_user":false}'), 'every followup suppresses all mentions');
            ojCheck(str_contains($body, '【本人限定・別DBテスト】'), 'private results explicitly name disposable test');
            return $this->failure === 'patch' ? self::json(401, ['message' => 'FICTIONAL_CALLBACK_ERROR']) : self::json(200, ['id' => '900000000000009002']);
        }
        if ($method === 'DELETE') { ojSame('/api/v10/channels/' . Scope::VOICE_ID . '/messages/' . self::MESSAGE, $path, 'delete binds exactly the confirmed own VC message'); return $this->failure === 'delete' ? self::json(403, []) : new HttpResponse(204); }
        if ($method === 'POST') {
            ojSame('/api/v10/channels/' . Scope::VOICE_ID . '/messages', $path, 'sole public POST targets attached VC chat');
            $body = json_decode($request->body(), true, 20, JSON_THROW_ON_ERROR);
            ojSame(Trial::NONCE, $body['nonce'], 'fixed nonce supplied'); ojSame(true, $body['enforce_nonce'], 'nonce uniqueness requested');
            ojSame(4096, $body['flags'], 'public test suppresses notifications'); ojSame(false, $body['tts'], 'no TTS');
            ojSame(['parse' => [], 'users' => [], 'roles' => [], 'replied_user' => false], $body['allowed_mentions'], 'public message suppresses all mentions');
            ojSame(2, count($body['components'][0]['components']), 'only participation and card buttons');
            if ($this->failure === 'post_timeout') { return new HttpResponse(0, [], '', 'transport_timeout'); }
            $response = $body; $response['id'] = self::MESSAGE; $response['channel_id'] = Scope::VOICE_ID;
            $response['author'] = ['id' => $this->failure === 'post_foreign' ? '900000000000009099' : Scope::APPLICATION_ID, 'bot' => true];
            $response['components'][0]['id'] = 1; $response['components'][0]['components'][0]['id'] = 2;
            return self::json(200, $response);
        }
        ojSame('GET', $method, 'only expected HTTP methods');
        if ($path === '/api/v10/users/@me') { return $this->failure === 'identity' ? self::json(401, ['message' => 'FICTIONAL_BOT_ERROR']) : self::json(200, ['id' => Scope::APPLICATION_ID, 'bot' => true]); }
        if ($path === '/api/v10/oauth2/applications/@me') { return self::json(200, ['id' => Scope::APPLICATION_ID, 'bot' => ['id' => Scope::APPLICATION_ID]]); }
        if ($path === '/api/v10/guilds/' . Scope::GUILD_ID) { return self::json(200, ['id' => Scope::GUILD_ID, 'owner_id' => Scope::OWNER_ID]); }
        if ($path === '/api/v10/guilds/' . Scope::GUILD_ID . '/members/' . Scope::APPLICATION_ID) { return self::json(200, ['user' => ['id' => Scope::APPLICATION_ID], 'roles' => [], 'pending' => false]); }
        if ($path === '/api/v10/guilds/' . Scope::GUILD_ID . '/roles') { return self::json(200, [['id' => Scope::GUILD_ID, 'permissions' => (string)(1024 | 2048 | 32768 | 1048576), 'position' => 0, 'managed' => false]]); }
        foreach ([Scope::GUIDE_ID, Scope::RECORDS_ID, Scope::VOICE_ID] as $channel) {
            if ($path === '/api/v10/channels/' . $channel) { return self::json(200, ['id' => $channel, 'guild_id' => Scope::GUILD_ID, 'type' => $channel === Scope::VOICE_ID ? 2 : 0, 'permission_overwrites' => [], 'parent_id' => null, 'nsfw' => true]); }
        }
        if ($path === '/api/v10/guilds/' . Scope::GUILD_ID . '/voice-states/' . Scope::OWNER_ID) {
            if ($this->failure === 'rate') { return self::json(429, ['retry_after' => 30, 'global' => true]); }
            if ($this->failure === 'presence_forbidden') { return self::json(403, ['message' => 'FICTIONAL_RESPONSE_BODY_NOT_TO_BE_RECORDED']); }
            if ($this->failure === 'presence_timeout') { return new HttpResponse(0, [], '', 'transport_timeout'); }
            if ($this->failure === 'presence_parse') { return new HttpResponse(200, [], '{"FICTIONAL_RESPONSE_BODY_NOT_TO_BE_RECORDED":'); }
            if ($this->presence === 'unknown') { return self::json(404, ['code' => 10065, 'message' => 'FICTIONAL_ABSENCE']); }
            if ($this->presence === 'malformed') { return self::json(200, ['user_id' => '900000000000009088', 'channel_id' => Scope::VOICE_ID]); }
            return self::json(200, ['guild_id' => Scope::GUILD_ID, 'user_id' => Scope::OWNER_ID, 'channel_id' => $this->presence === 'inside' ? Scope::VOICE_ID : Scope::GUIDE_ID, 'self_mute' => true, 'self_deaf' => true]);
        }
        throw new RuntimeException('Unexpected offline route');
    }
    private static function json(int $status, array $body): HttpResponse { return new HttpResponse($status, [], json_encode($body, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)); }
}

$base = sys_get_temp_dir() . '/radio-onejoin-tests-' . bin2hex(random_bytes(8)); mkdir($base, 0700);
$failure = null; $skip = 0;
try {
    (static function () use ($base, &$skip): void {
        $pair = sodium_crypto_sign_seed_keypair(hash('sha256', 'FICTIONAL_ONE_JOIN_KEY', true));
        $publicKey = bin2hex(sodium_crypto_sign_publickey($pair)); $secretKey = sodium_crypto_sign_secretkey($pair);
        $number = 0; $interaction = 0;
        $fixture = static function () use ($base, $publicKey, &$number): array {
            $root = $base . '/case-' . ++$number; $private = $root . '/private'; $public = $root . '/public_html';
            foreach ([$root, $private, $private . '/instances', $private . '/var', $public] as $directory) { mkdir($directory, 0700); }
            $sourceDb = $private . '/var/fictional-source.sqlite'; file_put_contents($sourceDb, 'FICTIONAL_SOURCE_DB_DO_NOT_TOUCH');
            $clock = new FrozenClock('2026-11-01T01:58:00+09:00'); $events = new OjEvents(); $driver = new OjDriver($events); $http = new OjHttp($events);
            $now = $clock->now()->getTimestamp();
            $activation = Window::activation($now - 300, $now - 240, $now - 60, $now);
            $config = ['mode' => 'admin_validation', 'admin_validation_enabled' => true, 'application_id' => Scope::APPLICATION_ID, 'guild_id' => Scope::GUILD_ID,
                'admin_user_ids' => [Scope::OWNER_ID], 'public_key_hex' => $publicKey, 'bot_token' => 'FICTIONAL_BOT_TOKEN_ONE_JOIN_ONLY',
                'token_encryption_key_hex' => hash('sha256', 'FICTIONAL_QUEUE_KEY_ONE_JOIN_ONLY'), 'private_db_path' => $sourceDb,
                'text_channel_id' => Scope::GUIDE_ID, 'records_channel_id' => Scope::RECORDS_ID, 'voice_channel_id' => Scope::VOICE_ID,
                'timezone' => 'Asia/Tokyo', 'member_actions_enabled' => false, 'live_automation_enabled' => false, 'role_ids' => []];
            $loads = (object)['count' => 0];
            $loader = static function () use ($config, $loads): array { $loads->count++; return $config; };
            $endpoint = new Endpoint($private, $public, $activation, $driver, $clock, $http, $loader);
            return compact('root','private','public','sourceDb','clock','events','driver','http','activation','config','endpoint','loads','loader');
        };
        $payload = static function (array $f, string $kind = 'check') use (&$interaction): array {
            $data = ['application_id' => Scope::APPLICATION_ID, 'type' => $kind === 'check' ? 2 : 3, 'id' => (string)(900000000000010000 + ++$interaction),
                'guild_id' => Scope::GUILD_ID, 'channel_id' => $kind === 'check' ? Scope::GUIDE_ID : Scope::VOICE_ID,
                'token' => 'FICTIONAL_CALLBACK_ONE_JOIN_' . $interaction, 'member' => ['user' => ['id' => Scope::OWNER_ID, 'username' => 'FICTIONAL_OWNER_NAME']]];
            if ($kind === 'check') { $data['data'] = ['id' => Trial::COMMAND_ID, 'type' => 1, 'name' => 'radio-check', 'options' => []]; }
            else {
                $state = (new Store($f['private'], $f['public'], $f['activation'], $f['clock'], false))->state();
                $data['data'] = ['component_type' => 2, 'custom_id' => $kind === 'attend' ? 'radio:attend:' . $state['session'] : 'radio:card:' . $state['month']];
                $data['message'] = ['id' => OjHttp::MESSAGE, 'author' => ['id' => Scope::APPLICATION_ID, 'bot' => true]];
            }
            return $data;
        };
        $prepare = static function (array $f, array $data, bool $valid = true) use ($secretKey): \RadioStamp\Validation\OneJoin\Delivery {
            $raw = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR); $stamp = (string)$f['clock']->now()->getTimestamp();
            $server = ['REQUEST_METHOD' => 'POST', 'HTTP_X_SIGNATURE_TIMESTAMP' => $stamp, 'HTTP_X_SIGNATURE_ED25519' => $valid ? bin2hex(sodium_crypto_sign_detached($stamp . $raw, $secretKey)) : str_repeat('0', 128)];
            $before = count($f['http']->calls); $f['events']->finished = false;
            $delivery = $f['endpoint']->prepare($server, $raw); ojSame($before, count($f['http']->calls), 'prepare makes no HTTP calls'); return $delivery;
        };
        $deliver = static function (array $f, array $data) use ($prepare): \RadioStamp\Validation\OneJoin\Delivery {
            $delivery = $prepare($f, $data); ojSame(200, $delivery->response->status, 'accepted request status'); ojSame(5, $delivery->response->body['type'], 'normal ephemeral defer'); ojSame(64, $delivery->response->body['data']['flags'], 'defer remains private');
            ojCheck($delivery->deliver($f['driver'], static function (Response $response) use ($f): void { $f['events']->items[] = 'emit'; }), 'delivery succeeds');
            $before = count($f['http']->calls); ojCheck(!$delivery->deliver($f['driver'], static function (): void { throw new RuntimeException('must not repeat'); }), 'delivery is single-use'); ojSame($before, count($f['http']->calls), 'repeat invokes no HTTP');
            return $delivery;
        };
        $store = static fn(array $f): Store => new Store($f['private'], $f['public'], $f['activation'], $f['clock'], false);
        $methods = static fn(array $f, string $method): int => count(array_filter($f['http']->calls, static fn(array $r): bool => $r['method'] === $method));
        $preserved = static function (array $f): void { ojSame('FICTIONAL_SOURCE_DB_DO_NOT_TOUCH', file_get_contents($f['sourceDb']), 'source DB byte-for-byte untouched'); ojCheck(!file_exists($f['sourceDb'] . '-wal') && !file_exists($f['sourceDb'] . '-shm'), 'source DB never opened as SQLite'); };

        $inactive = require __DIR__ . '/fixtures/one-join-disabled.php';
        ojSame(false, $inactive['enabled'], 'shipped activation OFF'); ojCheck(!Trial::validActivation($inactive), 'shipped candidate cannot be activated accidentally');
        foreach ([600, 3600, 0, -1] as $duration) { $a = ['enabled' => true, 'starts_at' => 1000000000, 'expires_at' => 1000000000 + $duration]; ojCheck(!Trial::validActivation($a), 'legacy activation without actual invitation and join timestamps is rejected'); }
        $f = $fixture(); $get = $f['endpoint']->prepare(['REQUEST_METHOD' => 'GET'], ''); ojSame(405, $get->response->status, 'GET read-only fixed response'); ojSame(0, $f['loads']->count, 'GET never loads credentials');
        $disabled = new Endpoint($f['private'], $f['public'], $inactive, $f['driver'], $f['clock'], $f['http'], $f['loader']);
        ojSame(503, $disabled->prepare(['REQUEST_METHOD' => 'POST'], '{}')->response->status, 'inactive POST unavailable'); ojSame(0, $f['loads']->count, 'inactive POST never loads credentials');
        $ping = $prepare($f, ['type' => 1, 'application_id' => Scope::APPLICATION_ID]); ojSame(['type' => 1], $ping->response->body, 'signed PING exact PONG'); $ping->deliver($f['driver'], static function (): void {}); ojCheck(!Store::exists($f['private']), 'PING creates no fixture'); ojSame([], $f['http']->calls, 'PING performs no REST');
        $later = $f['activation']; $later['starts_at'] += 60;
        $future = new Endpoint($f['private'], $f['public'], $later, $f['driver'], $f['clock'], $f['http'], $f['loader']); $loadsBefore = $f['loads']->count;
        ojSame(503, $future->prepare(['REQUEST_METHOD' => 'POST'], '{}')->response->status, 'future window unavailable'); ojSame($loadsBefore, $f['loads']->count, 'future window never loads credentials');
        $past = $fixture(); $past['clock']->advance(Window::overallDeadline($past['activation']) - $past['clock']->now()->getTimestamp());
        ojSame(503, $past['endpoint']->prepare(['REQUEST_METHOD'=>'POST'], '{}')->response->status, 'overall deadline refuses before any credential load');
        ojSame(0, $past['loads']->count, 'overall expiry reads no credentials'); ojSame([], $past['http']->calls, 'overall expiry calls no API'); ojCheck(!Store::exists($past['private']), 'overall expiry creates no trial DB'); $preserved($past);
        $lateStart = $fixture(); $lateStart['clock']->advance($lateStart['activation']['expires_at'] - $lateStart['clock']->now()->getTimestamp() - 299);
        $lateStartDelivery = $prepare($lateStart, $payload($lateStart));
        ojSame(503, $lateStartDelivery->response->status, 'first check refuses when five verification minutes no longer remain');
        ojSame([], $lateStart['http']->calls, 'late preparation sends no API'); ojCheck(!Store::exists($lateStart['private']), 'late preparation creates no instance'); $preserved($lateStart);
        $slowCheck = $fixture(); $delayed = false;
        $slowCheck['http']->hook = static function () use (&$slowCheck, &$delayed): void {
            if ($delayed) { return; } $delayed = true;
            $slowCheck['clock']->advance($slowCheck['activation']['expires_at'] - $slowCheck['clock']->now()->getTimestamp() - 299);
        };
        $deliver($slowCheck, $payload($slowCheck));
        ojSame('failed', $store($slowCheck)->state()['phase'], 'slow check cannot publish after losing its verification reserve');
        ojSame(0, $methods($slowCheck, 'POST'), 'slow preflight never posts controls');
        ojSame(0, $store($slowCheck)->counts()['attendance'], 'slow preflight never adds attendance'); $preserved($slowCheck);
        foreach (['signature','owner','guild','app','command','channel','missing_token','missing_id','wrong_type','disabled_member','roles','non_owner_config'] as $case) {
            $negative = $fixture(); $data = $payload($negative);
            switch ($case) {
                case 'owner': $data['member']['user']['id'] = '900000000000019999'; break;
                case 'guild': $data['guild_id'] = '900000000000019999'; break;
                case 'app': $data['application_id'] = '900000000000019999'; break;
                case 'command': $data['data']['id'] = '900000000000019999'; break;
                case 'channel': $data['channel_id'] = Scope::RECORDS_ID; break;
                case 'missing_token': unset($data['token']); break;
                case 'missing_id': unset($data['id']); break;
                case 'wrong_type': $data['type'] = '2'; break;
                case 'disabled_member': case 'roles': case 'non_owner_config':
                    $bad = $negative['config'];
                    if ($case === 'disabled_member') { $bad['member_actions_enabled'] = true; }
                    elseif ($case === 'roles') { $bad['role_ids'] = [1 => '900000000000019999']; }
                    else { $bad['admin_user_ids'] = ['900000000000019999']; }
                    $negative['endpoint'] = new Endpoint($negative['private'], $negative['public'], $negative['activation'], $negative['driver'], $negative['clock'], $negative['http'], static fn(): array => $bad);
            }
            $response = $prepare($negative, $data, $case !== 'signature')->response;
            ojCheck($response->status >= 400, 'invalid scope rejected'); ojSame([], $negative['http']->calls, 'invalid scope performs no HTTP'); ojCheck(!Store::exists($negative['private']), 'invalid scope creates no fixture'); $preserved($negative);
        }
        foreach (['no_driver','false_finish','throw_finish','emit_exception'] as $case) {
            $negative = $fixture(); if ($case === 'no_driver') { $negative['driver']->supported = false; }
            $data = $payload($negative); $delivery = $prepare($negative, $data);
            if ($case === 'no_driver') { ojSame(503, $delivery->response->status, 'no finisher rejected before queue'); ojCheck(!Store::exists($negative['private']), 'no finisher creates no DB'); continue; }
            $negative['driver']->finishes = $case !== 'false_finish'; $negative['driver']->throws = $case === 'throw_finish';
            ob_start(); $ok = $delivery->deliver($negative['driver'], static function () use ($case): void { if ($case === 'emit_exception') { throw new RuntimeException('FICTIONAL_EMIT_SECRET'); } }); $bytes = ob_get_clean();
            ojSame(false, $ok, 'failed emit/finish reports false'); ojSame('', $bytes, 'no exception output'); ojSame([], $negative['http']->calls, 'failed emit/finish makes no HTTP'); ojSame('failed', $store($negative)->state()['phase'], 'failed ACK stops trial');
            ojCheck(!str_contains(file_get_contents($store($negative)->root . '/report.safe.json'), 'FICTIONAL'), 'safe report excludes fictional secret');
            $preserved($negative);
        }

        foreach (['inside'] as $presence) {
            $f = $fixture(); $f['http']->presence = $presence; $init = $payload($f); $deliver($f, $init); $s = $store($f);
            ojSame('inside', $s->state()['phase'], 'check starts with joined owner and no repeated outside probe'); ojSame(1, $methods($f, 'POST'), 'one public message'); ojSame(9, $methods($f, 'GET'), 'normal identity + fixed checks');
            $before = count($f['http']->calls); $replay = $prepare($f, $init); ojSame(4, $replay->response->body['type'], 'same interaction replay is private immediate result'); $replay->deliver($f['driver'], static function (): void {}); ojSame($before, count($f['http']->calls), 'replay never invokes REST');
            // Wrong channel, message, actor and target cannot consume a stage.
            foreach (['channel','message','author','target','start','other_user','early_card'] as $case) {
                $bad = $payload($f, $case === 'early_card' ? 'card' : 'attend');
                if ($case === 'channel') { $bad['channel_id'] = Scope::GUIDE_ID; }
                if ($case === 'message') { $bad['message']['id'] = '900000000000019999'; }
                if ($case === 'author') { $bad['message']['author']['id'] = '900000000000019999'; }
                if ($case === 'target') { $bad['data']['custom_id'] = 'radio:attend:2026-10-31_0700'; }
                if ($case === 'start') { $bad['data']['custom_id'] = 'radio:start:' . $s->state()['session']; }
                if ($case === 'other_user') { $bad['member']['user']['id'] = '900000000000019999'; }
                $d = $prepare($f, $bad); $d->deliver($f['driver'], static function (): void {}); ojSame($before, count($f['http']->calls), 'off-scope button sends no REST'); ojSame('inside', $s->state()['phase'], 'off-scope button consumes no stage');
            }
            $during = false;
            $f['http']->hook = static function (string $method, string $path) use (&$during, &$f, $payload, $prepare): void {
                if ($during || !str_contains($path, '/voice-states/')) { return; } $during = true;
                $pending = $prepare($f, $payload($f, 'attend')); ojSame(4, $pending->response->body['type'], 'concurrent second press gets private busy result');
                $f['events']->finished = true;
            };
            $deliver($f, $payload($f, 'attend')); $f['http']->hook = null;
            ojSame('duplicate', $s->state()['phase'], 'muted/deaf owner in fixed VC accepted'); ojSame(1, $s->counts()['attendance'], 'first positive adds one'); ojSame(1, $s->counts()['total'], 'total is one');
            $deliver($f, $payload($f, 'attend')); ojSame('card', $s->state()['phase'], 'new interaction duplicate detected'); ojSame(1, $s->counts()['attendance'], 'duplicate keeps one row');
            $deliver($f, $payload($f, 'card')); ojSame('complete', $s->state()['phase'], 'monthly card completes same-join sequence'); ojSame('deleted', $s->state()['cleanup'], 'shared test message deleted automatically');
            ojSame(1, $methods($f, 'POST'), 'no repeated public post'); ojSame(1, $methods($f, 'DELETE'), 'own message deleted once'); ojSame(4, $methods($f, 'PATCH'), 'only four original ephemeral responses updated');
            ojSame(['attendance' => 1, 'total' => 1, 'month' => 1, 'awards' => 0, 'effects' => 0], $s->counts(), 'fixture has one stamp and no role/public effects');
            $report = file_get_contents($s->root . '/report.safe.json'); $decoded = json_decode($report, true, 20, JSON_THROW_ON_ERROR);
            ojSame(4, count($decoded['operations']), 'check and three bounded button operations');
            ojSame(2, $decoded['schema'], 'v2 report declares diagnostic schema');
            ojSame(null, $decoded['operations'][0]['presence'], 'check creates no fake presence result');
            foreach ([1, 2] as $presenceIndex) { ojSame(['http_status' => 200, 'classification' => 'ok'], $decoded['operations'][$presenceIndex]['presence'], 'inside and duplicate retain status without identities'); }
            ojSame(null, $decoded['operations'][3]['presence'], 'card does not invent a presence API request');
            foreach ($decoded['operations'] as $row) { ojCheck($row['ack_finished'] && $row['followup_ok'], 'each result follows ACK and private PATCH'); }
            ojSame(true, $decoded['operations'][3]['image_ok'], 'real local renderer generated valid PNG');
            foreach (['FICTIONAL', Scope::OWNER_ID, Scope::APPLICATION_ID, $f['private'], $init['id'], 'token', 'Authorization', 'username'] as $forbidden) { ojCheck(!str_contains($report, $forbidden), 'report excludes request, identity, credential and private path'); }
            $rows = $s->db->pdo()->query('SELECT token_ciphertext,state,attempts FROM interaction_jobs')->fetchAll();
            foreach ($rows as $row) { ojSame('', $row['token_ciphertext'], 'completed callback token erased'); ojSame(1, (int)$row['attempts'], 'no automatic worker retry'); }
            foreach (['fixture.sqlite','fixture.sqlite-wal','fixture.sqlite-shm'] as $name) { $p = $s->root . '/' . $name; if (is_file($p)) { ojCheck(!str_contains(file_get_contents($p), $init['token']), 'no plaintext callback in DB or WAL'); } }
            $before = count($f['http']->calls); $extra = $prepare($f, $payload($f, 'attend')); $extra->deliver($f['driver'], static function (): void {}); ojSame($before, count($f['http']->calls), 'post-completion buttons perform no REST');
            $preserved($f);
        }

        foreach ([['outside',200,'ok','not_in_voice'], ['unknown',404,'not_found','voice_unknown'], ['presence_forbidden',403,'forbidden','voice_unknown'], ['presence_timeout',null,'transport_timeout','voice_unknown'], ['presence_parse',200,'response_invalid','voice_unknown'], ['malformed',200,'response_invalid','voice_unknown']] as [$case,$status,$classification,$code]) {
            $f = $fixture(); $deliver($f, $payload($f));
            if (in_array($case, ['outside','unknown','malformed'], true)) { $f['http']->presence = $case; } else { $f['http']->failure = $case; }
            $deliver($f, $payload($f, 'attend')); $s = $store($f);
            $report = file_get_contents($s->root . '/report.safe.json'); $decoded = json_decode($report, true, 20, JSON_THROW_ON_ERROR);
            ojSame($code, $decoded['operations'][1]['code'], 'absence and unknown remain distinct without attendance');
            ojSame(['http_status'=>$status,'classification'=>$classification], $decoded['operations'][1]['presence'], '403, transport and response failures remain distinguishable in receipt');
            ojSame('failed', $s->state()['phase'], 'outside or unknown stops the positive-only trial');
            ojSame(0, $s->counts()['attendance'], 'failed presence never adds attendance'); ojSame(4, $decoded['operations'][1]['rest_calls'], 'bounded self, presence, private result and own-post cleanup only');
            ojCheck(!str_contains($report, 'FICTIONAL'), 'neither response body nor credential is copied into receipt'); $preserved($f);
        }
        $f = $fixture(); $deliver($f, $payload($f)); $s = $store($f);
        $s->db->pdo()->exec("CREATE TRIGGER fictional_diagnostic_failure BEFORE UPDATE OF presence_classification ON trial_operations WHEN NEW.step='inside' BEGIN SELECT RAISE(FAIL,'FICTIONAL_STORAGE_DETAIL_NOT_TO_BE_RECORDED'); END");
        $f['http']->presence = 'inside'; $deliver($f, $payload($f,'attend'));
        ojSame('failed', $s->state()['phase'], 'unrecorded presence cannot advance the trial'); ojSame(0, $s->counts()['attendance'], 'diagnostic write failure fails closed even with valid presence');
        $report = file_get_contents($s->root . '/report.safe.json'); $decoded = json_decode($report,true,20,JSON_THROW_ON_ERROR);
        ojSame(null,$decoded['operations'][1]['presence'],'failed write is not fabricated'); ojCheck(!str_contains($report,'FICTIONAL'),'storage exception text is not exported'); $preserved($f);

        $f = $fixture(); $deliver($f, $payload($f)); $data = $payload($f,'attend'); $prepare($f,$data); $s = $store($f);
        $diagnostic = PresenceDiagnostic::fromResult(new RestResult(false,'not_found',404));
        $rejected=false; try{$s->recordPresence($data['id'],$diagnostic);}catch(RuntimeException){$rejected=true;}
        ojCheck($rejected,'diagnostic cannot be stored before formal ACK'); ojSame(false,$s->presenceRecorded($data['id']),'pre-ACK rejection leaves both fields unset');
        $s->ack($data['id'],'lt_100ms'); ojCheck($s->start($data['id']),'offline operation enters one running state'); $s->recordPresence($data['id'],$diagnostic);
        $rejected=false; try{$s->recordPresence($data['id'],PresenceDiagnostic::fromResult(new RestResult(false,'forbidden',403)));}catch(RuntimeException){$rejected=true;}
        ojCheck($rejected,'same observation cannot be overwritten'); ojSame(404,(int)$s->operation($data['id'])['presence_http_status'],'original diagnostic is immutable');
        ojSame(11,count($f['http']->calls),'diagnostic storage itself performs no HTTP'); $preserved($f);

        foreach (['post_timeout','post_foreign','identity','patch','delete','rate','late_presence','unexpected_absent','malformed_presence','abort','expired_button','expired_abort'] as $case) {
            $f = $fixture();
            if (in_array($case, ['post_timeout','post_foreign','identity','patch'], true)) { $f['http']->failure = $case; }
            $deliver($f, $payload($f)); $s = $store($f);
            if (in_array($case, ['post_timeout','post_foreign','identity','patch'], true)) {
                ojSame('failed', $s->state()['phase'], 'initial transport failures stop trial'); ojSame(0, $s->counts()['attendance'], 'initial failure never adds attendance');
                $before = count($f['http']->calls); (new Publisher(new GuardedHttp($f['http'], $s, '900000000000019999', $f['clock']), $s, $f['config']['bot_token']))->publish(true); ojSame($before, count($f['http']->calls), 'failed POST cannot be retried');
                if (in_array($case, ['post_timeout','post_foreign'], true)) { ojSame('manual_required', $s->state()['cleanup'], 'ambiguous/foreign POST never authorizes an arbitrary delete'); ojSame(0, $methods($f, 'DELETE'), 'no unconfirmed ID deleted'); }
                $preserved($f); continue;
            }
            if ($case === 'expired_button' || $case === 'expired_abort') { $f['clock']->advance($f['activation']['expires_at'] - $f['clock']->now()->getTimestamp()); }
            if ($case === 'abort' || $case === 'expired_abort') {
                $deliver($f, $payload($f)); ojSame('stopped', $s->state()['phase'], 'second check is cleanup only, including after expiry'); ojSame('deleted', $s->state()['cleanup'], 'cleanup check removes owned message'); ojSame(1, $methods($f, 'POST'), 'abort never posts another message'); ojSame(0, $s->counts()['attendance'], 'abort does not write attendance');
                $third = $prepare($f, $payload($f)); ojCheck($third->response->interactionId === null, 'third check cannot enqueue'); $preserved($f); continue;
            }
            if ($case === 'expired_button') { $before = count($f['http']->calls); $d = $prepare($f, $payload($f, 'attend')); ojSame(4, $d->response->body['type'], 'expired button gets private rejection'); $d->deliver($f['driver'], static function (): void {}); ojSame($before, count($f['http']->calls), 'expired button makes no HTTP'); ojSame(0, $s->counts()['attendance'], 'expired button adds zero'); $preserved($f); continue; }
            if ($case === 'delete') {
                $deliver($f, $payload($f, 'attend')); $deliver($f, $payload($f, 'attend')); $f['http']->failure = 'delete'; $deliver($f, $payload($f, 'card'));
                ojSame('manual_required', $s->state()['cleanup'], 'failed delete remains explicitly unconfirmed'); ojSame(1, $methods($f, 'DELETE'), 'no delete retry'); $preserved($f); continue;
            }
            if ($case === 'rate') { $f['http']->failure = 'rate'; }
            if ($case === 'unexpected_absent') { $f['http']->presence = 'outside'; }
            if ($case === 'malformed_presence') { $f['http']->presence = 'malformed'; }
            if ($case === 'late_presence') { $f['http']->hook = static function (string $method, string $path) use (&$f): void { if (str_contains($path, '/voice-states/')) { $f['clock']->advance($f['activation']['expires_at'] - $f['clock']->now()->getTimestamp()); } }; }
            $deliver($f, $payload($f, 'attend')); $f['http']->hook = null;
            if ($case === 'malformed_presence') { ojSame('failed', $s->state()['phase'], 'malformed presence stops as unknown'); ojSame(0, $s->counts()['attendance'], 'malformed presence adds zero'); }
            else { ojSame('failed', $s->state()['phase'], 'unexpected or expired result stops trial'); }
            if ($case === 'rate') { ojSame(true, $s->state()['api_suspended'], '429 suspends all further API calls'); ojSame('GET', end($f['http']->calls)['method'], 'no PATCH or DELETE follows 429'); }
            if ($case === 'late_presence') { ojSame(0, $s->counts()['attendance'], 'late presence cannot extend fixture window'); }
            $preserved($f);
        }

        // The adapter cannot be used before ACK or outside its private operation.
        $f = $fixture(); $data = $payload($f); $prepare($f, $data); $s = $store($f); $guard = new GuardedHttp($f['http'], $s, $data['id'], $f['clock']);
        ojSame(0, $guard->exchange(new HttpRequest('GET', 'https://discord.com/api/v10/users/@me', ['Authorization: Bot FICTIONAL_TOKEN']))->status, 'guard blocks before ACK'); ojSame([], $f['http']->calls, 'pre-ACK guard never delegates');
        $s->ack($data['id'], 'lt_100ms'); ojCheck($s->start($data['id']), 'offline guard fixture marks one running operation'); $f['events']->finished = true;
        $unsafe = [
            new HttpRequest('GET', 'https://discord.com/api/v10/guilds/' . Scope::GUILD_ID . '/voice-states/900000000000019999', ['Authorization: Bot FICTIONAL_TOKEN']),
            new HttpRequest('POST', 'https://discord.com/api/v10/channels/' . Scope::GUIDE_ID . '/messages', ['Authorization: Bot FICTIONAL_TOKEN'], '{}'),
            new HttpRequest('POST', 'https://discord.com/api/v10/channels/' . Scope::VOICE_ID . '/messages', ['Authorization: Bot FICTIONAL_TOKEN'], '{}'),
            new HttpRequest('DELETE', 'https://discord.com/api/v10/channels/' . Scope::VOICE_ID . '/messages/' . OjHttp::MESSAGE, ['Authorization: Bot FICTIONAL_TOKEN']),
            new HttpRequest('PATCH', 'https://discord.com/api/v10/guilds/' . Scope::GUILD_ID . '/members/' . Scope::OWNER_ID, ['Authorization: Bot FICTIONAL_TOKEN'], '{}'),
            new HttpRequest('POST', 'https://discord.com/api/v10/applications/' . Scope::APPLICATION_ID . '/guilds/' . Scope::GUILD_ID . '/commands', ['Authorization: Bot FICTIONAL_TOKEN'], '{}'),
        ];
        foreach ($unsafe as $request) { ojSame(0, $guard->exchange($request)->status, 'guard rejects out-of-scope operation after ACK'); }
        ojSame([], $f['http']->calls, 'scope refusal never delegates to HTTP');
        for ($i = 0; $i < 12; ++$i) { ojSame(200, $guard->exchange(new HttpRequest('GET', 'https://discord.com/api/v10/users/@me', ['Authorization: Bot FICTIONAL_TOKEN']))->status, 'bounded allowed read works'); }
        ojSame(0, $guard->exchange(new HttpRequest('GET', 'https://discord.com/api/v10/users/@me', ['Authorization: Bot FICTIONAL_TOKEN']))->status, 'per-operation API budget exhausted'); ojSame(12, count($f['http']->calls), 'budget refusal adds no exchange');
        foreach (['DELETE','PUT'] as $verb) {
            foreach (['https://discord.com/api/v10/guilds/' . Scope::GUILD_ID, 'https://discord.com/api/v10/channels/' . Scope::GUIDE_ID . '/messages/' . OjHttp::MESSAGE] as $url) {
                try { new HttpRequest($verb, $url, []); ojCheck(false, 'unsafe mutation constructor rejected'); } catch (InvalidArgumentException) { ojCheck(true, 'unsafe mutation constructor rejected'); }
            }
        }
        $changed = $f['activation']; $changed['invitation_sent_at']++;
        ojCheck(Trial::validActivation($changed), 'changed timestamp remains structurally valid');
        $rejected = false;
        try { new Store($f['private'], $f['public'], $changed, $f['clock'], false); } catch (RuntimeException) { $rejected = true; }
        ojCheck($rejected, 'existing instance rejects modified human timing metadata');
        $before = count($f['http']->calls);
        $f['clock']->advance(Window::overallDeadline($f['activation']) - $f['clock']->now()->getTimestamp());
        ojSame(0, $guard->exchange(new HttpRequest('GET', 'https://discord.com/api/v10/users/@me', ['Authorization: Bot FICTIONAL_TOKEN']))->status, 'running old operation cannot use API after overall deadline');
        ojSame($before, count($f['http']->calls), 'late running operation delegates nothing');
        $deadlineDelivery = $prepare($f, $payload($f));
        ojSame(503, $deadlineDelivery->response->status, 'cleanup command cannot bypass one-hour deadline');
        ojSame($before, count($f['http']->calls), 'late cleanup command sends no HTTP');
        $preserved($f);
        $foreign = $fixture(); $foreignRoot = $foreign['private'] . '/instances/' . Trial::NAME; mkdir($foreignRoot, 0700); file_put_contents($foreignRoot . '/keep.txt', 'FOREIGN_FIXTURE_SENTINEL');
        ojSame(503, $prepare($foreign, $payload($foreign))->response->status, 'pre-existing foreign folder rejected'); ojSame('FOREIGN_FIXTURE_SENTINEL', file_get_contents($foreignRoot . '/keep.txt'), 'foreign file preserved'); ojCheck(!file_exists($foreignRoot . '/fixture.sqlite'), 'foreign folder receives no DB');
        $f = $fixture(); $publicPrivate = $f['public'] . '/private'; mkdir($publicPrivate); mkdir($publicPrivate . '/instances');
        try { new Store($publicPrivate, $f['public'], $f['activation'], $f['clock'], true); ojCheck(false, 'public storage rejected'); } catch (Throwable) { ojCheck(true, 'public storage rejected'); }
        ojCheck(!Store::exists($publicPrivate), 'public storage rejection occurs before mkdir');
        $link = $f['private'] . '/instances/' . Trial::NAME;
        if (@symlink($f['private'] . '/var', $link)) {
            try { new Store($f['private'], $f['public'], $f['activation'], $f['clock'], true); ojCheck(false, 'symlink root rejected'); } catch (Throwable) { ojCheck(true, 'symlink root rejected'); }
            unlink($link);
        } else { ++$skip; }
    })();
} catch (Throwable $caught) { $failure = $caught->getMessage(); }
finally {
    gc_collect_cycles();
    $owned = realpath($base); $tmp = realpath(sys_get_temp_dir());
    if ($owned !== false && dirname($owned) === $tmp && str_starts_with(basename($owned), 'radio-onejoin-tests-')) {
        $remove = static function (string $directory) use (&$remove): void {
            foreach (new DirectoryIterator($directory) as $entry) { if ($entry->isDot()) { continue; } if ($entry->isLink()) { unlink($entry->getPathname()); } elseif ($entry->isDir()) { $remove($entry->getPathname()); rmdir($entry->getPathname()); } else { unlink($entry->getPathname()); } }
        }; $remove($owned); rmdir($owned);
    }
}
if ($failure !== null) { fwrite(STDERR, $failure . "\n"); exit(1); }
echo 'One-join trial: ' . $oneJoinAssertions . ' assertions passed (fictional HTTP + disposable local SQLite only); ' . $skip . " platform-dependent group skipped.\n";
