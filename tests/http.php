<?php
declare(strict_types=1);
require_once __DIR__.'/artifact_directory.php';
require dirname(__DIR__) . '/bootstrap.php';

use RadioStamp\Cards\CardRenderer;
use RadioStamp\Core\Database;
use RadioStamp\Core\FrozenClock;
use RadioStamp\Core\RadioService;
use RadioStamp\Discord\MockGateway;
use RadioStamp\Http\InteractionController;
use RadioStamp\Http\InteractionQueue;
use RadioStamp\Http\InteractionWorker;
use RadioStamp\Http\LocalEndpoint;
use RadioStamp\Http\MockFollowupSink;
use RadioStamp\Http\PrivateConfig;
use RadioStamp\Security\SignatureVerifier;
use RadioStamp\Security\TokenCipher;

$httpAssertions = 0;
function httpCheck(bool $condition, string $description): void
{
    global $httpAssertions;
    if (!$condition) { throw new RuntimeException('HTTP test failed: ' . $description); }
    $httpAssertions++;
}
function httpRejects(callable $operation): bool
{
    try { $operation(); return false; } catch (Throwable) { return true; }
}

// Deliberately fictional, deterministic test material; no real app or Bot is used.
$keyPair = sodium_crypto_sign_seed_keypair(str_repeat("\x07", SODIUM_CRYPTO_SIGN_SEEDBYTES));
$testSecret = sodium_crypto_sign_secretkey($keyPair);
$publicKey = bin2hex(sodium_crypto_sign_publickey($keyPair));
$clock = new FrozenClock('2026-10-17T06:50:00+09:00');
$now = $clock->now()->getTimestamp();
$private = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'radio-http-' . bin2hex(random_bytes(8));
mkdir($private, 0700);
$dbPath = $private . DIRECTORY_SEPARATOR . 'http.sqlite';
$config = [
    'mode' => 'mock', 'admin_validation_enabled' => true,
    'application_id' => '900000000000000001', 'guild_id' => '900000000000000003',
    'admin_user_ids' => ['900000000000000002'], 'public_key_hex' => $publicKey,
    'token_encryption_key_hex' => str_repeat('42', 32), 'private_db_path' => $dbPath,
    'voice_channel_id' => '900000000000000004', 'text_channel_id' => '900000000000000005',
    'records_channel_id' => '900000000000000006', 'destination_channel_id' => null,
    'timezone' => 'Asia/Tokyo', 'member_actions_enabled' => false, 'role_ids' => [],
    'max_roster_age_seconds' => 5,
];
$headersFor = static function (string $body, ?int $timestamp = null) use ($testSecret, $clock): array {
    $stamp = (string) ($timestamp ?? $clock->now()->getTimestamp());
    return ['X-Signature-Timestamp' => $stamp, 'X-Signature-Ed25519' => bin2hex(sodium_crypto_sign_detached($stamp . $body, $testSecret))];
};
$data = [
    'id' => '900000000000000101', 'application_id' => $config['application_id'], 'type' => 3,
    'guild_id' => $config['guild_id'], 'channel_id' => $config['voice_channel_id'],
    'token' => 'FICTIONAL_TEST_CALLBACK_TOKEN_NEVER_REAL',
    'member' => ['nick' => '検証担当', 'user' => ['id' => $config['admin_user_ids'][0], 'username' => 'fictional_admin']],
    'data' => ['component_type' => 2, 'custom_id' => 'radio:start:2026-10-17_0700'],
];
$encode = static fn(array $value): string => json_encode($value, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
$db = new Database($dbPath, 200);
$pdo = $db->pdo();
$cipher = new TokenCipher($config['token_encryption_key_hex']);
$queue = new InteractionQueue($pdo, $cipher);
$controller = new InteractionController($config, $queue, $clock);
$send = static function (array $payload) use ($controller, $encode, $headersFor) {
    $body = $encode($payload);
    return $controller->handle('POST', $headersFor($body), $body);
};
try {
    $body = $encode($data);
    $start = hrtime(true);
    $response = $controller->handle('POST', $headersFor($body), $body);
    httpCheck($response->status === 200 && $response->body === ['type' => 5, 'data' => ['flags' => 64]], 'ephemeral defer');
    httpCheck((hrtime(true) - $start) / 1e9 < 2.5, 'defer completes well below 3 seconds');
    httpCheck($pdo->query('SELECT COUNT(*) FROM interaction_jobs')->fetchColumn() === 1, 'one durable job');
    httpCheck($send($data)->body === $response->body && $pdo->query('SELECT COUNT(*) FROM interaction_jobs')->fetchColumn() === 1, 'signed replay deterministic ACK and one job');
    $changed = $data; $changed['data']['custom_id'] = 'radio:attend:2026-10-17_0700';
    httpCheck($send($changed)->status === 409, 'same ID different signed body rejected');
    $changed = $data; $changed['member']['user']['id'] = '900000000000000099';
    httpCheck($send($changed)->status === 403, 'nonallowlisted administrator rejected');
    $changed = $data; $changed['guild_id'] = '900000000000000099';
    httpCheck($send($changed)->status === 403, 'other guild rejected');
    $changed = $data; unset($changed['guild_id']);
    httpCheck($send($changed)->status === 403, 'direct message rejected');
    $changed = $data; $changed['channel_id'] = '900000000000000099';
    httpCheck($send($changed)->status === 403, 'other channel rejected');
    foreach ([$config['text_channel_id'], $config['records_channel_id']] as $copiedChannel) {
        foreach (['start', 'attend'] as $copiedAction) {
            $changed = $data; $changed['channel_id'] = $copiedChannel;
            $changed['data']['custom_id'] = 'radio:' . $copiedAction . ':2026-10-17_0700';
            $refused = $send($changed);
            httpCheck($refused->status === 200 && $refused->body['type'] === 4 && $refused->body['data']['flags'] === 64, 'copied controls receive a private refusal');
            httpCheck($refused->body['data']['allowed_mentions'] === \RadioStamp\Discord\Gateway::ALLOWED_MENTIONS, 'wrong-place refusal cannot ping');
        }
    }
    httpCheck($pdo->query('SELECT COUNT(*) FROM interaction_jobs')->fetchColumn() === 1, 'copied controls are never queued');
    $changed = $data; $changed['application_id'] = '900000000000000099';
    httpCheck($send($changed)->status === 403, 'other application rejected');
    foreach (['radio:delete:2026-10-17_0700', 'radio:start:2026-02-30_0700', 'radio:start:2026-10-17_0715', 'radio:card:2026-13', 'radio:start:../../etc/passwd'] as $badAction) {
        $changed = $data; $changed['data']['custom_id'] = $badAction;
        httpCheck($send($changed)->status === 400, 'unknown or invalid action rejected');
    }
    httpCheck($controller->handle('POST', $headersFor($body), $body . ' ')->status === 401, 'raw JSON whitespace modification invalidates signature');
    httpCheck($controller->handle('POST', $headersFor($body, $now - 301), $body)->status === 401, 'stale signed request rejected');
    httpCheck($controller->handle('POST', $headersFor($body, $now + 31), $body)->status === 401, 'future signed request rejected');
    $badHeaders = $headersFor($body); $badHeaders['X-Signature-Ed25519'] = str_repeat('z', 128);
    httpCheck($controller->handle('POST', $badHeaders, $body)->status === 401, 'malformed signature rejected');
    $badHeaders['X-Signature-Ed25519'] = str_repeat('00', 64);
    httpCheck($controller->handle('POST', $badHeaders, $body)->status === 401, 'invalid Ed25519 signature rejected');
    $badHeaders['X-Signature-Timestamp'] = '0' . $now;
    httpCheck($controller->handle('POST', $badHeaders, $body)->status === 401, 'noncanonical timestamp rejected');
    httpCheck($controller->handle('POST', $headersFor('{broken'), '{broken')->status === 400, 'verified malformed JSON rejected');
    httpCheck($controller->handle('POST', [], '{broken')->status === 401, 'signature rejected before malformed JSON parsing');
    httpCheck($controller->handle('GET', [], '')->status === 405, 'GET rejected');
    httpCheck($controller->handle('POST', [], str_repeat('x', 65537))->status === 413, 'body size bounded');
    $ping = ['application_id' => $config['application_id'], 'type' => 1];
    httpCheck($send($ping)->body === ['type' => 1], 'verified application PING');
    $ping['application_id'] = '900000000000000099';
    httpCheck($send($ping)->status === 403, 'PING application identity checked');
    $disabled = $config; $disabled['mode'] = 'live';
    httpCheck((new InteractionController($disabled, $queue, $clock))->handle('POST', $headersFor($body), $body)->status === 503, 'live transport disabled');
    $disabled = $config; $disabled['admin_user_ids'] = [];
    httpCheck((new InteractionController($disabled, $queue, $clock))->handle('POST', $headersFor($body), $body)->status === 503, 'missing owner scope fails closed');
    httpCheck(!PrivateConfig::ready([]), 'unconfigured production fails closed');
    httpCheck(httpRejects(fn() => PrivateConfig::requirePrivatePath(dirname(__DIR__) . '/public/config.php', dirname(__DIR__) . '/public')), 'public secret storage refused');

    $stored = $pdo->query('SELECT * FROM interaction_jobs')->fetch(PDO::FETCH_ASSOC);
    httpCheck(!str_contains(json_encode($stored), $data['token']), 'no plaintext callback token in DB row');
    httpCheck($cipher->decrypt($data['id'], $stored['token_ciphertext']) === $data['token'], 'private key decrypts bound token');
    httpCheck(httpRejects(fn() => $cipher->decrypt('900000000000000999', $stored['token_ciphertext'])), 'ciphertext bound to interaction ID');
    httpCheck(httpRejects(fn() => (new TokenCipher(str_repeat('43', 32)))->decrypt($data['id'], $stored['token_ciphertext'])), 'wrong private key rejected');
    $again = $cipher->encrypt($data['id'], $data['token']);
    httpCheck($again !== $stored['token_ciphertext'], 'each ciphertext has a fresh nonce');
    foreach (glob($dbPath . '*') as $sqliteFile) {
        httpCheck(!str_contains(file_get_contents($sqliteFile), $data['token']), 'no plaintext callback in SQLite database or WAL');
    }

    $lease = $queue->claim($now);
    httpCheck($lease !== null && $queue->claim($now) === null, 'one worker lease at a time');
    $reclaimed = $queue->claim($now + 121);
    httpCheck($reclaimed !== null && $reclaimed['interaction_id'] === $lease['interaction_id'] && $reclaimed['lease_id'] !== $lease['lease_id'], 'crashed worker lease retries original interaction');
    $queue->finish($lease, 'obsolete_worker');
    httpCheck($pdo->query('SELECT state FROM interaction_jobs')->fetchColumn() === 'processing', 'obsolete worker cannot complete new lease');
    $queue->retry($reclaimed, 'test_retry', $now + 121);
    httpCheck($queue->claim($now + 121) === null, 'retry backoff enforced');
    $reclaimed = $queue->claim($now + 130);
    $queue->finish($reclaimed, 'lease_test_done');
    httpCheck($pdo->query('SELECT token_ciphertext FROM interaction_jobs')->fetchColumn() === '', 'successful job token immediately erased');

    $expired = $data; $expired['id'] = '900000000000000102';
    httpCheck($send($expired)->status === 200, 'expiry test enqueued');
    httpCheck($queue->claim($now + 900) === null, '15 minute callback not executed');
    $expiredRow = $pdo->query("SELECT state, token_ciphertext FROM interaction_jobs WHERE interaction_id='900000000000000102'")->fetch();
    httpCheck($expiredRow['state'] === 'expired' && $expiredRow['token_ciphertext'] === '', '15 minute callback ciphertext erased on worker cleanup');

    // Hold a real file write lock through a second connection. The ACK must fail
    // promptly, and it must not imply that the interaction was durably enqueued.
    $locking = new PDO('sqlite:' . $dbPath, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    $locking->exec('BEGIN IMMEDIATE');
    $locked = $data; $locked['id'] = '900000000000000103';
    $start = hrtime(true);
    httpCheck($send($locked)->status === 503, 'busy private queue returns retriable failure');
    httpCheck((hrtime(true) - $start) / 1e9 < 2.5, 'locked queue fails below 3 seconds');
    $start = hrtime(true);
    $configFile = $private . DIRECTORY_SEPARATOR . 'fictional-config.php';
    file_put_contents($configFile, '<?php return ' . var_export($config, true) . ';');
    $lockedBody = $encode($locked);
    $current = time();
    $signed = $headersFor($lockedBody, $current);
    $server = ['REMOTE_ADDR' => '127.0.0.1', 'REQUEST_METHOD' => 'POST', 'HTTP_X_SIGNATURE_TIMESTAMP' => $signed['X-Signature-Timestamp'], 'HTTP_X_SIGNATURE_ED25519' => $signed['X-Signature-Ed25519']];
    $endpoint = new LocalEndpoint(dirname(__DIR__) . '/public');
    httpCheck($endpoint->handle($server, $lockedBody, $configFile)->status === 503, 'full fresh HTTP connection route lock fails closed');
    httpCheck((hrtime(true) - $start) / 1e9 < 2.5, 'fresh connection schema lock bounded below 3 seconds');
    $locking->exec('ROLLBACK');
    $locking = null;
    httpCheck($pdo->query("SELECT COUNT(*) FROM interaction_jobs WHERE interaction_id='900000000000000103'")->fetchColumn() === 0, 'failed ACK did not leave a job');
    httpCheck($endpoint->handle($server, $lockedBody, null)->status === 503, 'public entrypoint refuses missing config');
    $server['REMOTE_ADDR'] = '203.0.113.10';
    httpCheck($endpoint->handle($server, $lockedBody, $configFile)->body['error'] === 'local_administrator_validation_only', 'public entrypoint refuses external addresses');

    // Core integration: mocked voice state; no gateway, token, or followup network.
    $gateway = new MockGateway($clock);
    $gateway->setVoiceState($config['admin_user_ids'][0], $config['voice_channel_id']);
    $service = new RadioService($db, $gateway, $clock, $config);
    $service->planSessions(new DateTimeImmutable('2026-10-17T00:00:00+09:00'), new DateTimeImmutable('2026-10-18T00:00:00+09:00'));
    $service->tick();
    $sink = new MockFollowupSink();
    $renderer = new CardRenderer(['C:/Windows/Fonts/BIZ-UDGothicR.ttc', 'C:/Windows/Fonts/msgothic.ttc']);
    $worker = new InteractionWorker($queue, $service, $renderer, $sink, $clock);
    $startJob = $data; $startJob['id'] = '900000000000000201';
    httpCheck($send($startJob)->status === 200, 'start queued');
    httpCheck($worker->runOnce()['code'] === 'started', 'worker dispatches core start');
    $attendJob = $data; $attendJob['id'] = '900000000000000202'; $attendJob['data']['custom_id'] = 'radio:attend:2026-10-17_0700';
    httpCheck($send($attendJob)->status === 200, 'attendance queued');
    $sink->succeed = false;
    httpCheck($worker->runOnce()['state'] === 'retry', 'failed mock followup retries');
    $clock->advance(3);
    $sink->succeed = true;
    httpCheck($worker->runOnce()['code'] === 'attended', 'retried attendance returns saved core result');
    httpCheck($service->cardData($config['admin_user_ids'][0], '検証担当', '2026-10')['total_count'] === 1, 'worker retry counts attendance once');
    httpCheck($send($attendJob)->status === 200 && $worker->runOnce() === null, 'redelivery after completion does not dispatch twice');
    $card = $data; $card['id'] = '900000000000000203'; $card['data']['custom_id'] = 'radio:card:2026-10';
    httpCheck($send($card)->status === 200 && $worker->runOnce()['code'] === 'card_ready', 'card button renders through worker');
    httpCheck($sink->records[$card['id']]['png_bytes'] > 1000 && $sink->records[$card['id']]['transport'] === 'mock_only', 'mock card delivery records PNG evidence only');
    httpCheck($sink->records[$card['id']]['result']['components'] === [], 'first day exposes no removed day-benefit button');
    httpCheck(!str_contains(json_encode($sink->records), $data['token']), 'mock followup records redact callback token');
    httpCheck(count($sink->records) === 3, 'one mock followup per successful interaction');
    $memorial = $data; $memorial['id'] = '900000000000000204'; $memorial['data']['custom_id'] = 'radio:memorial:10';
    httpCheck($send($memorial)->status === 200 && $worker->runOnce()['code'] === 'memorial_locked', 'unearned memorial cannot be retrieved');
    httpCheck($sink->records[$memorial['id']]['png_bytes'] === 0, 'unearned memorial emits no PNG');
    $removedMemorial=$data;$removedMemorial['id']='900000000000000205';$removedMemorial['data']['custom_id']='radio:memorial:1';
    httpCheck($send($removedMemorial)->status===400&&$worker->runOnce()===null,'new first-day benefit request is rejected before queueing');
    httpCheck(!isset($sink->records[$removedMemorial['id']]),'removed first-day request produces no followup or PNG');
    $legacyDayOneId='900000000000000206';$queue->enqueue(['interaction_id'=>$legacyDayOneId,'action'=>'memorial','target'=>'1',
        'user_id'=>$config['admin_user_ids'][0],'display_name'=>'架空の旧queue','request_hash'=>hash('sha256','fictional-old-day-one-queue')],$data['token'],$clock->now()->getTimestamp());
    $removedRenderCalls=0;$blockedWorker=new InteractionWorker($queue,$service,static function()use(&$removedRenderCalls){++$removedRenderCalls;throw new RuntimeException('Removed day benefit must not render.');},$sink,$clock);
    httpCheck($blockedWorker->runOnce($legacyDayOneId)['code']==='memorial_locked','old pending first-day queue entry cannot issue the removed benefit');
    httpCheck($removedRenderCalls===0&&$sink->records[$legacyDayOneId]['png_bytes']===0,'old pending first-day entry never invokes renderer or generates PNG');
    $completedDayOneId='900000000000000207';$queue->enqueue(['interaction_id'=>$completedDayOneId,'action'=>'memorial','target'=>'1',
        'user_id'=>$config['admin_user_ids'][0],'display_name'=>'架空の過去完了','request_hash'=>hash('sha256','fictional-completed-day-one')],$data['token'],$clock->now()->getTimestamp());
    $pdo->prepare("UPDATE interaction_jobs SET state='done',result_code='memorial_ready',token_ciphertext='' WHERE interaction_id=?")->execute([$completedDayOneId]);
    $completedBefore=$pdo->query("SELECT * FROM interaction_jobs WHERE interaction_id='".$completedDayOneId."'")->fetch();
    httpCheck($blockedWorker->runOnce($completedDayOneId)===null,'completed historical first-day job is not replayed as a new award');
    httpCheck($completedBefore===$pdo->query("SELECT * FROM interaction_jobs WHERE interaction_id='".$completedDayOneId."'")->fetch()&&!isset($sink->records[$completedDayOneId]),'completed historical first-day result stays unchanged without a new send');

    // Private fixture: keep actual FK/unique constraints and all 100 attendance
    // records consistent with the lifetime total. No live DB or Discord is used.
    $fixtureUser = $config['admin_user_ids'][0];
    $db->transaction(function (PDO $fixture) use ($fixtureUser, $now): void {
        $fixture->prepare('UPDATE attendance SET ordinal=100, stamp=? WHERE user_id=?')->execute(['IC', $fixtureUser]);
        $sessionInsert = $fixture->prepare('INSERT INTO sessions (id,scheduled_at,count_date,opens_at,unstarted_closes_at,status,started_at,closes_at,warning_sent_at,closed_at) VALUES (?,?,?,?,?,\'closed\',?,?,?,?)');
        $attendanceInsert = $fixture->prepare('INSERT INTO attendance (session_id,user_id,count_date,attended_at,ordinal,stamp) VALUES (?,?,?,?,?,?)');
        $awardDates = [];
        for ($ordinal = 1; $ordinal <= 99; $ordinal++) {
            $instant = (new DateTimeImmutable('2026-07-01T07:00:00+09:00'))->modify('+' . ($ordinal - 1) . ' days');
            $date = $instant->format('Y-m-d');
            $seconds = $instant->getTimestamp();
            $sessionId = $date . '_0700';
            $sessionInsert->execute([$sessionId, $seconds, $date, $seconds - 1800, $seconds + 1800, $seconds, $seconds + 900, $seconds + 840, $seconds + 900]);
            $attendanceInsert->execute([$sessionId, $fixtureUser, $date, $seconds, $ordinal, ['IC', 'コンデンサ', 'LED'][($ordinal - 1) % 3]]);
            if (in_array($ordinal, [10, 30, 60], true)) { $awardDates[$ordinal] = $seconds; }
        }
        $fixture->prepare('UPDATE participants SET total_count=100, latest_threshold=80, latest_title=? WHERE user_id=?')->execute(['⚙️ 暖機運転', $fixtureUser]);
        $awardInsert = $fixture->prepare('INSERT INTO memorial_awards (user_id,milestone,reached_at) VALUES (?,?,?)');
        foreach ([10, 30, 60, 100] as $threshold) { $awardInsert->execute([$fixtureUser, $threshold, $awardDates[$threshold] ?? $now]); }
    });
    $acquired = $data; $acquired['id'] = '900000000000000240'; $acquired['data']['custom_id'] = 'radio:card:2026-10';
    httpCheck($send($acquired)->status === 200 && $worker->runOnce()['code'] === 'card_ready', 'lifetime 100 monthly card rendered');
    $buttons = $sink->records[$acquired['id']]['result']['components'][0]['components'];
    httpCheck(array_column($buttons, 'custom_id') === ['radio:memorial:10', 'radio:memorial:30', 'radio:memorial:60', 'radio:memorial:100'], '100 distinct dates expose four retained day milestones');
    $memorialHashes = [];
    foreach ($buttons as $index => $button) {
        $earned = $data;
        $earned['id'] = (string) (900000000000000220 + $index);
        $earned['data']['custom_id'] = $button['custom_id'];
        httpCheck($send($earned)->status === 200 && $worker->runOnce()['code'] === 'memorial_ready', 'earned memorial button dispatches renderer');
        $delivered = $sink->records[$earned['id']];
        httpCheck($delivered['png_bytes'] > 1000 && $delivered['png_signature_valid'] === true, 'earned memorial delivers PNG through mock boundary');
        $memorialHashes[] = $delivered['png_sha256'];
    }
    httpCheck(count(array_unique($memorialHashes)) === 4, 'four retained day values produce distinct temporary cards');
    httpCheck(count($service->cardData($fixtureUser, '検証担当', '2026-10')['awards']) === 4, 'retrieving temporary cards preserves current eligibility without restoring day one');

    $clock->set('2026-10-18T03:50:00+09:00');
    $gateway->setVoiceState($fixtureUser, $config['voice_channel_id']);
    $service->planSessions(new DateTimeImmutable('2026-10-18T00:00:00+09:00'), new DateTimeImmutable('2026-10-19T00:00:00+09:00'));
    $service->tick();
    $earlyStart = $data; $earlyStart['id'] = '900000000000000301'; $earlyStart['data']['custom_id'] = 'radio:start:2026-10-18_0400';
    httpCheck($send($earlyStart)->body === ['type' => 5, 'data' => ['flags' => 64]], '04:00 start passes signed ephemeral ACK');
    httpCheck($worker->runOnce()['code'] === 'started', '04:00 start reaches core through worker');
    $earlyAttend = $data; $earlyAttend['id'] = '900000000000000302'; $earlyAttend['data']['custom_id'] = 'radio:attend:2026-10-18_0400';
    httpCheck($send($earlyAttend)->body === ['type' => 5, 'data' => ['flags' => 64]], '04:00 attendance passes signed ephemeral ACK');
    httpCheck($worker->runOnce()['code'] === 'attended', '04:00 attendance reaches core through worker');
    $earlyData = $service->cardData($fixtureUser, '検証担当', '2026-10');
    httpCheck($earlyData['total_count'] === 101 && count($earlyData['days']['2026-10-18']) === 1, '04:00 attendance counted once on scheduled JST date');
    httpCheck($send($earlyAttend)->status === 200 && $worker->runOnce() === null, '04:00 signed redelivery does not dispatch twice');

    $dangling = $private . DIRECTORY_SEPARATOR . 'dangling.sqlite';
    $symlinkSkipped = !@symlink($private . DIRECTORY_SEPARATOR . 'missing.sqlite', $dangling);
    if (!$symlinkSkipped) {
        httpCheck(httpRejects(fn() => PrivateConfig::requirePrivatePath($dangling, dirname(__DIR__) . '/public')), 'dangling private DB symbolic link refused');
    }

    echo "HTTP: {$httpAssertions} assertions passed (mock only)." . ($symlinkSkipped ? ' Symbolic-link creation unavailable; dangling-link runtime test skipped.' : '') . "\n";
} finally {
    $send = $controller = $queue = $worker = $blockedWorker = $service = $freshController = $freshQueue = $freshDb = $db = $pdo = null;
    if (isset($locking) && $locking instanceof PDO) { try { $locking->exec('ROLLBACK'); } catch (Throwable) {} }
    $locking = null;
    foreach (glob($private . DIRECTORY_SEPARATOR . '*') as $ownedFile) {
        if ((is_file($ownedFile) || is_link($ownedFile)) && dirname($ownedFile) === $private) { unlink($ownedFile); }
    }
    rmdir($private);
}
