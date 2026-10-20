<?php
declare(strict_types=1);
require_once __DIR__.'/artifact_directory.php';
require dirname(__DIR__) . '/bootstrap.php';

use RadioStamp\Core\Database;
use RadioStamp\Core\FrozenClock;
use RadioStamp\Core\RadioService;
use RadioStamp\Discord\MockGateway;
use RadioStamp\Discord\ButtonBuilder;

$rsCoreChecks = 0;
$rsCoreDirectories = [];
function rsCoreAssert(bool $condition, string $message): void
{
    global $rsCoreChecks;
    $rsCoreChecks++;
    if (!$condition) { throw new RuntimeException('Core assertion failed: ' . $message); }
}
function rsCoreSame(mixed $expected, mixed $actual, string $message): void { rsCoreAssert($expected === $actual, $message . ' expected=' . json_encode($expected, JSON_UNESCAPED_UNICODE) . ' actual=' . json_encode($actual, JSON_UNESCAPED_UNICODE)); }
function rsCoreFixture(string $at = '2026-10-17 07:00:00+09:00', array $extra = []): array
{
    global $rsCoreDirectories;
    $dir = sys_get_temp_dir() . '/radio-stamp-core-' . bin2hex(random_bytes(8));
    mkdir($dir, 0700);
    $rsCoreDirectories[] = $dir;
    $db = new Database($dir . '/application.sqlite');
    $clock = new FrozenClock($at);
    $mock = new MockGateway($clock);
    $config = $extra + ['guild_id' => 'mock-guild', 'voice_channel_id' => 'mock-exercise', 'text_channel_id' => 'mock-guide', 'records_channel_id' => 'mock-records', 'destination_channel_id' => 'mock-onsen', 'mode' => 'mock'];
    $service = new RadioService($db, $mock, $clock, $config);
    $service->planSessions(new DateTimeImmutable('2026-10-17 00:00:00+09:00'), new DateTimeImmutable('2026-10-18 23:59:59+09:00'));
    return [$db, $clock, $mock, $service, $dir . '/application.sqlite'];
}
function rsCoreMethods(MockGateway $mock, string $method): array { return array_values(array_filter($mock->actionLog, static fn(array $a) => $a['method'] === $method)); }
function rsCoreReminders(MockGateway $mock): array { return array_values(array_filter(rsCoreMethods($mock, 'sendMessage'), static fn(array $a) => str_starts_with($a['key'], 'reminder:'))); }
function rsCoreMemberActions(MockGateway $mock): array { return array_values(array_filter($mock->actionLog, static fn(array $a) => in_array($a['method'], ['moveMember', 'disconnectMember'], true))); }
function rsCoreSession(Database $db, string $id = '2026-10-17_0700'): array
{
    $q = $db->pdo()->prepare('SELECT * FROM sessions WHERE id=?'); $q->execute([$id]); return $q->fetch();
}
function rsCoreTickCode(array $tick, string $session = '2026-10-17_0700'): string
{
    foreach ($tick['sessions'] as $result) { if (($result['session_id'] ?? '') === $session) { return $result['code']; } }
    return 'not-processed';
}
function rsCoreStartAttend(FrozenClock $clock, MockGateway $mock, RadioService $service, string $id, string $user = 'mock-person'): array
{
    $mock->setVoiceState($user, 'mock-exercise');
    rsCoreSame('started', $service->start($id, $user, 'start-' . $id)['code'], 'session starts');
    return $service->attend($id, $user, 'attend-' . $id);
}

try {
    [$db, $clock, $mock, $service] = rsCoreFixture();
    $sessions = $service->planSessions(new DateTimeImmutable('2026-10-17 00:00:00+09:00'), new DateTimeImmutable('2026-10-17 23:59:59+09:00'));
    rsCoreSame(['2026-10-17_0000', '2026-10-17_0400', '2026-10-17_0700', '2026-10-17_1230', '2026-10-17_2200'], array_column($sessions, 'id'), 'five JST sessions including lunchtime');
    rsCoreSame('2026-10-16 23:30', (new DateTimeImmutable('@' . $sessions[0]['opens_at']))->setTimezone(new DateTimeZone('Asia/Tokyo'))->format('Y-m-d H:i'), 'midnight opens previous day');
    rsCoreSame('2026-10-17', $sessions[0]['count_date'], 'midnight count date is scheduled date');
    rsCoreSame(10, (int)$db->pdo()->query('SELECT COUNT(*) FROM sessions')->fetchColumn(), 'five-slot two-day planning is idempotent');
    $buttons = ButtonBuilder::sessionControls('2026-10-17_0700')[0]['components'];
    rsCoreSame(['radio:start:2026-10-17_0700', 'radio:attend:2026-10-17_0700', 'radio:card:2026-10'], array_column($buttons, 'custom_id'), 'button payload matches HTTP parser');
    rsCoreSame('radio:start:2026-10-17_0400', ButtonBuilder::sessionControls('2026-10-17_0400')[0]['components'][0]['custom_id'], 'four am button accepted');
    try { ButtonBuilder::sessionControls('2026-02-31_0700'); rsCoreAssert(false, 'bad date rejected'); } catch (InvalidArgumentException) { rsCoreAssert(true, 'bad date rejected'); }
    try { new RadioService($db, $mock, $clock, ['mode' => 'live']); rsCoreAssert(false, 'live mode rejected'); } catch (LogicException) { rsCoreAssert(true, 'live mode rejected'); }
    $clock->set('2026-10-17 06:29:59+09:00');
    rsCoreSame('session_not_open', $service->start('2026-10-17_0700', 'mock-person', 'early')['code'], 'opening boundary');
    $clock->advance(1);
    rsCoreSame('voice_unknown', $service->start('2026-10-17_0700', 'mock-person', 'unknown')['code'], 'unknown is not disconnected');
    $mock->setVoiceState('mock-person', null);
    rsCoreSame('not_in_voice', $service->start('2026-10-17_0700', 'mock-person', 'disconnected')['code'], 'confirmed disconnected cannot start');
    $mock->setVoiceState('mock-person', 'mock-elsewhere');
    rsCoreSame('not_in_voice', $service->start('2026-10-17_0700', 'mock-person', 'elsewhere')['code'], 'other VC cannot start');
    $mock->setVoiceState('mock-person', 'mock-exercise', true, $clock->now()->getTimestamp() - 6);
    rsCoreSame('voice_stale', $service->start('2026-10-17_0700', 'mock-person', 'stale')['code'], 'stale state cannot start');
    $mock->setVoiceState('mock-person', 'mock-exercise', true, $clock->now()->getTimestamp() + 1);
    rsCoreSame('voice_stale', $service->start('2026-10-17_0700', 'mock-person', 'future')['code'], 'future state cannot start');
    rsCoreSame('session_not_started', $service->attend('2026-10-17_0700', 'mock-person', 'before-start')['code'], 'attendance needs start');
    $mock->setVoiceState('mock-person', 'mock-exercise');
    $start = $service->start('2026-10-17_0700', 'mock-person', 'start');
    rsCoreSame('started', $start['code'], 'present person can start');
    rsCoreSame($start['started_at'] + 900, $start['closes_at'], 'fifteen-minute duration');
    rsCoreSame($start['started_at'] + 840, $start['warning_at'], 'fourteen-minute warning');
    $logCount = count($mock->actionLog);
    rsCoreSame($start, $service->start('2026-10-17_0700', 'mock-person', 'start'), 'same interaction returns same result');
    rsCoreSame($logCount, count($mock->actionLog), 'duplicate start has no new effects');
    rsCoreSame('interaction_conflict', $service->start('2026-10-17_0700', 'other-person', 'start')['code'], 'interaction identity bound to user');
    rsCoreSame('already_started', $service->start('2026-10-17_0700', 'mock-person', 'other-start')['code'], 'double click cannot restart');
    $mock->setVoiceState('mock-person', null, false);
    rsCoreSame('voice_unknown', $service->attend('2026-10-17_0700', 'mock-person', 'unknown-attend')['code'], 'unknown presence cannot register');
    $mock->setVoiceState('mock-person', 'mock-exercise');
    $attendance = $service->attend('2026-10-17_0700', 'mock-person', 'attend');
    rsCoreSame('attended', $attendance['code'], 'present person can attend');
    rsCoreSame('LED', $attendance['stamp'], '07:00 morning slot stays LED even on first attendance');
    rsCoreSame('⚡ はじめの通電', $attendance['latest_title'], 'first count title');
    rsCoreSame($attendance, $service->attend('2026-10-17_0700', 'mock-person', 'attend'), 'attendance replay deterministic');
    rsCoreSame('already_attended', $service->attend('2026-10-17_0700', 'mock-person', 'attend-again')['code'], 'one attendance per session');
    rsCoreSame(1, $service->cardData('mock-person', 'テスト氏名', '2026-10')['total_count'], 'count exactly once');
    $records = array_values(array_filter(rsCoreMethods($mock, 'sendMessage'), static fn($a) => $a['channel_id'] === 'mock-records'));
    rsCoreSame(0, count($records), 'attendance results are not posted to a shared records channel');
    rsCoreSame(0, (int)$db->pdo()->query("SELECT COUNT(*) FROM core_effects WHERE kind IN ('attendance_record','started')")->fetchColumn(), 'operation results create no public outbox');
    $controlMessages = array_values(array_filter(rsCoreMethods($mock, 'sendMessage'), static fn(array $a) => $a['components'] !== []));
    rsCoreSame(1, count($controlMessages), 'one controls message without ordinary-chat duplicate');
    rsCoreSame(0, (int)$db->pdo()->query("SELECT COUNT(*) FROM core_effects WHERE kind='entry_guide'")->fetchColumn(), 'no unused duplicate controls job is queued');
    rsCoreSame('mock-exercise', $controlMessages[0]['channel_id'], 'controls are placed in the target VC attached chat');
    foreach (rsCoreMethods($mock, 'sendMessage') as $message) {
        rsCoreSame(\RadioStamp\Discord\Gateway::ALLOWED_MENTIONS, $message['allowed_mentions'], 'channel messages cannot mention users or roles');
        rsCoreAssert(!str_contains($message['content'], 'mock-person'), 'shared messages do not expose individual operation results');
    }
    $legacy = $db->pdo()->prepare("INSERT INTO core_effects(effect_key,kind,payload_json,status) VALUES ('legacy-attendance','attendance_record',?,'pending')");
    $legacy->execute([json_encode(['user_id' => 'mock-person'] + $attendance, JSON_THROW_ON_ERROR)]);
    $service->tick();
    $records = array_filter(rsCoreMethods($mock, 'sendMessage'), static fn(array $a) => $a['channel_id'] === 'mock-records');
    rsCoreSame(0, count($records), 'an old pending public receipt is never replayed');
    $clock->set((new DateTimeImmutable('@' . ($start['closes_at'] - 1))));
    $mock->setVoiceState('late', 'mock-exercise');
    rsCoreSame('attended', $service->attend('2026-10-17_0700', 'late', 'last-second')['code'], 'last second registration');
    $clock->advance(1); $mock->setVoiceState('too-late', 'mock-exercise');
    rsCoreSame('session_closed', $service->attend('2026-10-17_0700', 'too-late', 'cutoff')['code'], 'cutoff independent from delayed movement');

    // Five sessions on a day are all counted; an early midnight start keeps tomorrow's date.
    [$db2, $clock2, $mock2, $service2] = rsCoreFixture();
    foreach (['0000' => '2026-10-16 23:35:00+09:00', '0400' => '2026-10-17 04:00:00+09:00', '0700' => '2026-10-17 07:00:00+09:00', '1230' => '2026-10-17 12:30:00+09:00', '2200' => '2026-10-17 22:00:00+09:00'] as $time => $instant) {
        $clock2->set($instant); rsCoreStartAttend($clock2, $mock2, $service2, '2026-10-17_' . $time);
    }
    $card = $service2->cardData('mock-person', '氏名', '2026-10');
    rsCoreSame(5, $card['month_count'], 'all five daily sessions count');
    rsCoreSame(['IC', 'コンデンサ', 'LED', 'プラグ', '電池'], $card['days']['2026-10-17'], 'five scheduled slots retain distinct fixed stamps and midnight date');
    rsCoreSame('already_attended', $service2->attend('2026-10-17_2200', 'mock-person', 'sixth-press')['code'], 'five daily sessions cannot be counted a sixth time');
    rsCoreSame(null, $service2->cardData('never', '初参加', '2026-10')['latest_title'], 'no invented title before attendance');
    try { $service2->cardData('mock-person', '氏名', '2026-13'); rsCoreAssert(false, 'invalid month rejected'); } catch (InvalidArgumentException) { rsCoreAssert(true, 'invalid month rejected'); }

    // Added 04:00 slot uses the same opening, warning, closing, and registration boundaries.
    [$db4am, $clock4am, $mock4am, $service4am] = rsCoreFixture('2026-10-17 03:29:59+09:00', ['member_actions_enabled' => true]);
    $fourId = '2026-10-17_0400';
    $fourSession = rsCoreSession($db4am, $fourId);
    rsCoreSame('03:30', (new DateTimeImmutable('@' . $fourSession['opens_at']))->setTimezone(new DateTimeZone('Asia/Tokyo'))->format('H:i'), 'four am opens at three thirty JST');
    rsCoreSame('04:30', (new DateTimeImmutable('@' . $fourSession['unstarted_closes_at']))->setTimezone(new DateTimeZone('Asia/Tokyo'))->format('H:i'), 'four am unstarted deadline four thirty JST');
    rsCoreSame('session_not_open', $service4am->start($fourId, 'mock-person', 'four-too-early')['code'], 'four am cannot start before opening');
    $clock4am->advance(1); $service4am->tick();
    rsCoreSame('open', rsCoreSession($db4am, $fourId)['status'], 'four am opens at exact three thirty boundary');
    $clock4am->set('2026-10-17 04:00:00+09:00');
    $fourAttendance = rsCoreStartAttend($clock4am, $mock4am, $service4am, $fourId);
    rsCoreSame('2026-10-17', $fourAttendance['count_date'], 'four am attendance retains scheduled date');
    $clock4am->advance(840);
    rsCoreSame('warning_grace', rsCoreTickCode($service4am->tick(), $fourId), 'four am warning at start plus fourteen minutes');
    $clock4am->advance(59); $service4am->tick();
    rsCoreSame([], rsCoreMemberActions($mock4am), 'four am warning grace protects members');
    $clock4am->advance(1); $mock4am->setRoster('mock-exercise', ['mock-person']); $mock4am->setVoiceState('mock-person', 'mock-exercise');
    rsCoreSame('closed', rsCoreTickCode($service4am->tick(), $fourId), 'four am started closes at start plus fifteen minutes');
    [$db4amIdle, $clock4amIdle, $mock4amIdle, $service4amIdle] = rsCoreFixture('2026-10-17 03:30:00+09:00', ['member_actions_enabled' => true]);
    $service4amIdle->tick(); $clock4amIdle->set('2026-10-17 04:29:00+09:00');
    rsCoreSame('warning_grace', rsCoreTickCode($service4amIdle->tick(), $fourId), 'unstarted four am warns at four twenty-nine');
    $clock4amIdle->advance(60); $mock4amIdle->setRoster('mock-exercise', ['four-visitor']); $mock4amIdle->setVoiceState('four-visitor', 'mock-exercise');
    rsCoreSame('closed', rsCoreTickCode($service4amIdle->tick(), $fourId), 'unstarted four am closes at four thirty');

    // Warn first. Unknown people remain unknown, and a failed move never becomes a disconnect.
    [$db3, $clock3, $mock3, $service3] = rsCoreFixture('2026-10-17 07:00:00+09:00', ['member_actions_enabled' => true]);
    rsCoreStartAttend($clock3, $mock3, $service3, '2026-10-17_0700');
    $clock3->advance(840); $service3->tick();
    $warnings = array_values(array_filter($mock3->actionLog, static fn($a) => str_starts_with($a['key'], 'warning:')));
    rsCoreSame(1, count($warnings), 'warning sent once');
    rsCoreSame('mock-exercise', $warnings[0]['channel_id'], 'warning goes to VC chat');
    $clock3->advance(59); $service3->tick();
    rsCoreSame([], rsCoreMemberActions($mock3), 'no actions before warning plus sixty seconds');
    $clock3->advance(1);
    $mock3->setRoster('mock-exercise', ['mock-person', 'unregistered', 'unknown-person']);
    $mock3->setVoiceState('mock-person', 'mock-exercise'); $mock3->setVoiceState('unregistered', 'mock-exercise');
    $mock3->setResult('moveMember', [false, true]);
    rsCoreSame('closure_pending', rsCoreTickCode($service3->tick()), 'failed move and unknown voice keep closure pending');
    rsCoreSame(1, count(rsCoreMethods($mock3, 'moveMember')), 'registered movement attempted');
    rsCoreSame(['unregistered'], array_column(rsCoreMethods($mock3, 'disconnectMember'), 'user_id'), 'only confirmed unregistered person disconnected');
    $clock3->advance(1); $mock3->setRoster('mock-exercise', ['mock-person', 'unknown-person']);
    $mock3->setVoiceState('mock-person', 'mock-exercise'); $mock3->setVoiceState('unknown-person', 'mock-other');
    rsCoreSame('closed', rsCoreTickCode($service3->tick()), 'safe retry completes');
    rsCoreSame(2, count(rsCoreMethods($mock3, 'moveMember')), 'failed move retried with same key');
    rsCoreSame(1, count(rsCoreMethods($mock3, 'disconnectMember')), 'no failed-move disconnect fallback');
    $actionsAfter = count(rsCoreMemberActions($mock3)); $service3->tick();
    rsCoreSame($actionsAfter, count(rsCoreMemberActions($mock3)), 'closed tick is idempotent');
    rsCoreSame('closed', rsCoreSession($db3)['status'], 'closed state persisted');
    $moveKey = rsCoreMethods($mock3, 'moveMember')[1]['key'];
    $mock3->setVoiceState('mock-person', 'mock-elsewhere');
    $mock3->moveMember('mock-guild', 'mock-person', 'mock-onsen', $moveKey);
    rsCoreSame('mock-elsewhere', $mock3->voiceState('mock-guild', 'mock-person')->channelId, 'mock successful key replay cannot move a member twice');

    // A failed or delayed warning always establishes a new real sixty-second grace period.
    [$db4, $clock4, $mock4, $service4] = rsCoreFixture('2026-10-17 07:00:00+09:00', ['member_actions_enabled' => true]);
    rsCoreStartAttend($clock4, $mock4, $service4, '2026-10-17_0700');
    $mock4->setResult('warning:2026-10-17_0700:started:' . $clock4->now()->getTimestamp(), [false, true]);
    $clock4->advance(840);
    rsCoreSame('warning_pending', rsCoreTickCode($service4->tick()), 'warning failure not treated as sent');
    $clock4->advance(60);
    rsCoreSame('warning_grace', rsCoreTickCode($service4->tick()), 'late successful warning starts grace');
    rsCoreSame($clock4->now()->getTimestamp(), (int)rsCoreSession($db4)['warning_sent_at'], 'success time persisted');
    $clock4->advance(59); $service4->tick();
    rsCoreSame([], rsCoreMemberActions($mock4), 'failed warning did not shorten grace');
    $clock4->advance(1); $mock4->setRoster('mock-exercise', ['mock-person']); $mock4->setVoiceState('mock-person', 'mock-exercise');
    rsCoreSame('closed', rsCoreTickCode($service4->tick()), 'closure one minute after actual warning');
    [$db5, $clock5, $mock5, $service5] = rsCoreFixture('2026-10-17 07:00:00+09:00', ['member_actions_enabled' => true]);
    rsCoreStartAttend($clock5, $mock5, $service5, '2026-10-17_0700');
    $clock5->advance(1200);
    rsCoreSame('warning_grace', rsCoreTickCode($service5->tick()), 'late worker warns instead of abruptly moving');
    rsCoreSame([], rsCoreMemberActions($mock5), 'late worker did not move');

    // Default disabled member action gate, roster freshness, unknown individual, and destination gate.
    [$db6, $clock6, $mock6, $service6] = rsCoreFixture();
    rsCoreStartAttend($clock6, $mock6, $service6, '2026-10-17_0700');
    $clock6->advance(840); $service6->tick(); $clock6->advance(60);
    $mock6->setRoster('mock-exercise', ['mock-person']); $mock6->setVoiceState('mock-person', 'mock-exercise');
    rsCoreSame('member_actions_disabled', rsCoreTickCode($service6->tick()), 'member actions disabled by default');
    rsCoreSame([], rsCoreMemberActions($mock6), 'default gate prevents any member action');
    [$db7, $clock7, $mock7, $service7] = rsCoreFixture('2026-10-17 07:00:00+09:00', ['member_actions_enabled' => true]);
    rsCoreStartAttend($clock7, $mock7, $service7, '2026-10-17_0700');
    $clock7->advance(840); $service7->tick(); $clock7->advance(60);
    rsCoreSame('voice_roster_unknown', rsCoreTickCode($service7->tick()), 'unknown roster cannot establish all members');
    $mock7->setRoster('mock-exercise', ['mock-person'], true, $clock7->now()->getTimestamp() - 6);
    rsCoreSame('voice_roster_stale', rsCoreTickCode($service7->tick()), 'stale roster cannot close');
    $mock7->setRoster('mock-exercise', ['mock-person']); $mock7->setVoiceState('mock-person', null, false);
    rsCoreSame('closure_pending', rsCoreTickCode($service7->tick()), 'unknown individual never classified absent');
    $mock7->setVoiceState('mock-person', 'mock-exercise', true, $clock7->now()->getTimestamp() - 6);
    rsCoreSame('closure_pending', rsCoreTickCode($service7->tick()), 'stale individual remains pending');
    rsCoreSame([], rsCoreMemberActions($mock7), 'uncertain presence causes no member action');
    $mock7->setVoiceState('mock-person', null);
    rsCoreSame('closed', rsCoreTickCode($service7->tick()), 'confirmed already disconnected needs no action');
    [$db8, $clock8, $mock8, $service8] = rsCoreFixture('2026-10-17 07:00:00+09:00', ['member_actions_enabled' => true, 'destination_channel_id' => null]);
    rsCoreStartAttend($clock8, $mock8, $service8, '2026-10-17_0700');
    $clock8->advance(840); $service8->tick(); $clock8->advance(60);
    rsCoreSame('destination_unverified', rsCoreTickCode($service8->tick()), 'unverified destination blocks all closure actions');

    // An unstarted opened session closes at schedule+30, with a warning a minute beforehand.
    [$db9, $clock9, $mock9, $service9] = rsCoreFixture('2026-10-17 06:30:00+09:00', ['member_actions_enabled' => true]);
    $service9->tick();
    rsCoreSame('open', rsCoreSession($db9)['status'], 'opens thirty minutes before schedule');
    $guides = array_values(array_filter(rsCoreMethods($mock9, 'sendMessage'), static fn($a) => $a['key'] === 'guide:2026-10-17_0700'));
    rsCoreSame(3, count($guides[0]['components'][0]['components']), 'open announcement includes real button payload');
    $clock9->set('2026-10-17 07:29:00+09:00');
    rsCoreSame('warning_grace', rsCoreTickCode($service9->tick()), 'unstarted warning at plus twenty-nine minutes');
    $clock9->advance(60); $mock9->setRoster('mock-exercise', ['visitor']); $mock9->setVoiceState('visitor', 'mock-exercise');
    rsCoreSame('closed', rsCoreTickCode($service9->tick()), 'unstarted closes at plus thirty minutes');
    rsCoreSame(['visitor'], array_column(rsCoreMethods($mock9, 'disconnectMember'), 'user_id'), 'unregistered visitor disconnected only after warning');

    // Starting after the unstarted warning must get a distinct fourteen-minute warning.
    [$dbRestart, $clockRestart, $mockRestart, $serviceRestart] = rsCoreFixture('2026-10-17 06:30:00+09:00', ['member_actions_enabled' => true]);
    $serviceRestart->tick();
    $clockRestart->set('2026-10-17 07:29:00+09:00'); $serviceRestart->tick();
    rsCoreAssert(rsCoreSession($dbRestart)['warning_sent_at'] !== null, 'unstarted warning already recorded');
    $clockRestart->advance(30); rsCoreStartAttend($clockRestart, $mockRestart, $serviceRestart, '2026-10-17_0700');
    rsCoreSame(null, rsCoreSession($dbRestart)['warning_sent_at'], 'start resets old phase warning');
    $clockRestart->advance(840); $serviceRestart->tick();
    $phaseWarnings = array_values(array_filter($mockRestart->actionLog, static fn($a) => str_starts_with($a['key'], 'warning:2026-10-17_0700:')));
    rsCoreSame(2, count($phaseWarnings), 'start phase emits a new warning at fourteen minutes');
    rsCoreAssert($phaseWarnings[0]['key'] !== $phaseWarnings[1]['key'], 'phase warnings use distinct durable effect keys');
    rsCoreSame($clockRestart->now()->getTimestamp(), (int)rsCoreSession($dbRestart)['warning_sent_at'], 'latest phase success time controls grace');
    $clockRestart->advance(59); $serviceRestart->tick();
    rsCoreSame([], rsCoreMemberActions($mockRestart), 'old warning cannot shortcut new grace');
    $clockRestart->advance(1); $mockRestart->setRoster('mock-exercise', ['mock-person']); $mockRestart->setVoiceState('mock-person', 'mock-exercise');
    rsCoreSame('closed', rsCoreTickCode($serviceRestart->tick()), 'closure at fifteen minutes from late start');

    // Five thirty-minute reminders: JST times, no link/mentions, durable per-session deduplication.
    [$dbReminder, $clockReminder, $mockReminder, $serviceReminder, $reminderPath] = rsCoreFixture('2026-10-16 23:29:59+09:00');
    $serviceReminder->tick();
    rsCoreSame([], rsCoreReminders($mockReminder), 'no reminder before opening window');
    foreach (['0000' => '2026-10-16 23:30:00+09:00', '0400' => '2026-10-17 03:30:00+09:00', '0700' => '2026-10-17 06:30:00+09:00', '1230' => '2026-10-17 12:00:00+09:00', '2200' => '2026-10-17 21:30:00+09:00'] as $slot => $opening) {
        $clockReminder->set($opening); $serviceReminder->tick(); $serviceReminder->tick();
        $matching = array_values(array_filter(rsCoreReminders($mockReminder), static fn($a) => $a['key'] === 'reminder:2026-10-17_' . $slot));
        rsCoreSame(1, count($matching), 'one reminder per scheduled session despite repeated ticks');
        rsCoreSame('mock-guide', $matching[0]['channel_id'], 'reminder goes to exercise announcement text channel');
        $time = substr((string)$slot, 0, 2) . ':' . substr((string)$slot, 2, 2);
        rsCoreSame("あと30分でラジオ体操だよー！⚡\n予定時刻: 2026-10-17 " . $time . ' JST', $matching[0]['content'], 'specified reminder wording and scheduled JST time');
        rsCoreAssert(!str_contains($matching[0]['content'], 'http') && !str_contains($matching[0]['content'], 'discord.com/channels/'), 'reminder contains no VC link');
        rsCoreSame(['parse' => [], 'users' => [], 'roles' => [], 'replied_user' => false], $matching[0]['allowed_mentions'], 'reminder cannot mention anyone');
    }
    rsCoreSame(5, count(rsCoreReminders($mockReminder)), 'all five slots notified exactly once');
    $dbReminderRestart = new Database($reminderPath);
    $mockReminderRestart = new MockGateway($clockReminder);
    $serviceReminderRestart = new RadioService($dbReminderRestart, $mockReminderRestart, $clockReminder, ['guild_id' => 'mock-guild', 'voice_channel_id' => 'mock-exercise', 'text_channel_id' => 'mock-guide', 'records_channel_id' => 'mock-records']);
    $serviceReminderRestart->tick();
    rsCoreSame([], rsCoreReminders($mockReminderRestart), 'new database connection and gateway do not replay successful reminders');
    $mockReminderRestart->sendMessage('mock-guide', '@everyone <@123> <@&456>', 'fixed-mention-policy');
    $arbitraryMessage = rsCoreMethods($mockReminderRestart, 'sendMessage')[0];
    rsCoreSame(['parse' => [], 'users' => [], 'roles' => [], 'replied_user' => false], $arbitraryMessage['allowed_mentions'], 'fixed suppression policy covers every message including hostile text');

    // Retry failed delivery with the same persistent key inside the opening window.
    [$dbReminderRetry, $clockReminderRetry, $mockReminderRetry, $serviceReminderRetry] = rsCoreFixture('2026-10-17 06:30:00+09:00');
    $mockReminderRetry->setResult('reminder:2026-10-17_0700', [false, true]);
    $serviceReminderRetry->tick(); $clockReminderRetry->advance(1); $serviceReminderRetry->tick();
    $reminderAttempts = rsCoreReminders($mockReminderRetry);
    rsCoreSame([false, true], array_column($reminderAttempts, 'ok'), 'failed reminder retried successfully');
    rsCoreSame(['reminder:2026-10-17_0700', 'reminder:2026-10-17_0700'], array_column($reminderAttempts, 'key'), 'retry uses the original durable key');
    rsCoreSame(2, (int)$dbReminderRetry->pdo()->query("SELECT attempts FROM core_effects WHERE effect_key='reminder:2026-10-17_0700'")->fetchColumn(), 'delivery attempts persisted');
    $clockReminderRetry->advance(1); $serviceReminderRetry->tick();
    rsCoreSame(2, count(rsCoreReminders($mockReminderRetry)), 'success suppresses further reminder delivery');

    // At scheduled time, failed reminders are permanently expired, including after restart.
    [$dbReminderExpiry, $clockReminderExpiry, $mockReminderExpiry, $serviceReminderExpiry, $expiryPath] = rsCoreFixture('2026-10-17 06:59:59+09:00');
    $mockReminderExpiry->setResult('reminder:2026-10-17_0700', false);
    $serviceReminderExpiry->tick();
    rsCoreSame(1, count(rsCoreReminders($mockReminderExpiry)), 'one failed last-second reminder attempt');
    $clockReminderExpiry->advance(1); $serviceReminderExpiry->tick();
    rsCoreSame(1, count(rsCoreReminders($mockReminderExpiry)), 'no retry at exact scheduled time');
    rsCoreSame($clockReminderExpiry->now()->getTimestamp(), (int)$dbReminderExpiry->pdo()->query("SELECT cancelled_at FROM core_effects WHERE effect_key='reminder:2026-10-17_0700'")->fetchColumn(), 'expiration time persisted');
    $dbReminderExpiryRestart = new Database($expiryPath);
    $mockReminderExpiryRestart = new MockGateway($clockReminderExpiry);
    $serviceReminderExpiryRestart = new RadioService($dbReminderExpiryRestart, $mockReminderExpiryRestart, $clockReminderExpiry, ['guild_id' => 'mock-guild', 'voice_channel_id' => 'mock-exercise', 'text_channel_id' => 'mock-guide', 'records_channel_id' => 'mock-records']);
    $serviceReminderExpiryRestart->tick();
    rsCoreSame([], rsCoreReminders($mockReminderExpiryRestart), 'expired reminder not replayed after restart');
    $clockReminderExpiry->advance(-1); $serviceReminderExpiryRestart->tick();
    rsCoreSame([], rsCoreReminders($mockReminderExpiryRestart), 'cancelled reminder remains cancelled even if local test clock moves backward');

    // A first worker boot after lunch never emits the past midnight/04:00/07:00/12:30 backlog.
    [$dbReminderLate, $clockReminderLate, $mockReminderLate, $serviceReminderLate] = rsCoreFixture('2026-10-17 13:00:00+09:00');
    $serviceReminderLate->tick();
    rsCoreSame([], rsCoreReminders($mockReminderLate), 'late startup has no reminder backlog');
    rsCoreSame(0, (int)$dbReminderLate->pdo()->query("SELECT COUNT(*) FROM core_effects WHERE kind='reminder'")->fetchColumn(), 'past sessions never enqueue reminders');
    $clockReminderLate->set('2026-10-17 21:30:00+09:00'); $serviceReminderLate->tick();
    rsCoreSame(['reminder:2026-10-17_2200'], array_column(rsCoreReminders($mockReminderLate), 'key'), 'late startup only notifies the next eligible slot');

    // Milestones, permanent memorials, slot stamps, no absence reset, latest decoration only.
    [$db10, $clock10, $mock10, $service10] = rsCoreFixture('2026-01-01 00:00:00+09:00', ['member_actions_enabled' => true, 'role_ids' => [1 => 'mock-role-1', 40 => 'mock-role-40', 80 => 'mock-role-80']]);
    $hundred = $service10->planSessions(new DateTimeImmutable('2026-01-01 00:00:00+09:00'), new DateTimeImmutable('2026-02-04 23:59:59+09:00'));
    $expectedTitles = [1 => '⚡ はじめの通電', 39 => '⚡ はじめの通電', 40 => '🔌 接続完了', 79 => '🔌 接続完了', 80 => '⚙️ 暖機運転', 100 => '⚙️ 暖機運転'];
    foreach (array_slice($hundred, 0, 100) as $index => $session) {
        $clock10->set(new DateTimeImmutable('@' . $session['scheduled_at']));
        $attend = rsCoreStartAttend($clock10, $mock10, $service10, $session['id']);
        rsCoreSame($index + 1, $attend['total_count'], 'lifetime total monotonic');
        if (isset($expectedTitles[$index + 1])) { rsCoreSame($expectedTitles[$index + 1], $attend['latest_title'], 'correct milestone title'); }
    }
    $all = $service10->cardData('mock-person', '長期参加者', '2026-02');
    rsCoreSame(100, $all['total_count'], 'hundred lifetime attendances');
    rsCoreSame(20, $all['total_days'], '100 sessions over 20 five-slot JST dates');
    rsCoreSame([10], $all['awards'], 'day milestones begin at ten days independently from 100 attendance records');
    rsCoreSame('⚙️ 暖機運転', $all['latest_title'], 'one latest count title');
    $db10->pdo()->prepare('UPDATE participants SET latest_title=? WHERE user_id=?')->execute(['legacy display label', 'mock-person']);
    rsCoreSame('⚙️ 暖機運転', $service10->cardData('mock-person', '長期参加者', '2026-02')['latest_title'], 'card title follows current labels without a record migration');
    rsCoreSame('legacy display label', $db10->pdo()->query("SELECT latest_title FROM participants WHERE user_id='mock-person'")->fetchColumn(), 'card display leaves the stored record unchanged');
    $service10->tick();
    rsCoreSame('mock-role-80', $mock10->decorationRoles['mock-person'], 'sync only latest count decorative role');
    rsCoreSame(1, count(rsCoreMethods($mock10, 'syncDecorationRole')), 'no replay of historical lower roles');
    $clock10->set('2028-01-01 07:00:00+09:00');
    $afterAbsence = $service10->cardData('mock-person', '長期参加者', '2028-01');
    rsCoreSame(0, $afterAbsence['month_count'], 'absence month has no stamps');
    rsCoreSame(100, $afterAbsence['total_count'], 'absence never resets lifetime count');
    rsCoreSame([10], $afterAbsence['awards'], 'absence never resets the retained day milestones');
    echo 'core: ' . $rsCoreChecks . " assertions passed\n";
} finally {
    unset($db, $db2, $db3, $db4, $db5, $db6, $db7, $db8, $db9, $db10, $dbRestart, $serviceRestart, $db4am, $service4am, $db4amIdle, $service4amIdle, $service, $service2, $service3, $service4, $service5, $service6, $service7, $service8, $service9, $service10, $dbReminder, $serviceReminder, $dbReminderRestart, $serviceReminderRestart, $dbReminderRetry, $serviceReminderRetry, $dbReminderExpiry, $serviceReminderExpiry, $dbReminderExpiryRestart, $serviceReminderExpiryRestart, $dbReminderLate, $serviceReminderLate);
    gc_collect_cycles();
    foreach ($rsCoreDirectories as $dir) {
        // Only the unique application-owned test directories created above are eligible.
        foreach (glob($dir . '/*') ?: [] as $file) { if (is_file($file)) { @unlink($file); } }
        @rmdir($dir);
    }
}
