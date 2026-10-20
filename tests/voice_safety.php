<?php
declare(strict_types=1);
require_once __DIR__.'/artifact_directory.php';
require dirname(__DIR__) . '/bootstrap.php';

use RadioStamp\Core\Database;
use RadioStamp\Core\FrozenClock;
use RadioStamp\Core\RadioService;
use RadioStamp\Discord\AtomicMemberGateway;
use RadioStamp\Discord\GatewayVoiceTracker;
use RadioStamp\Discord\MemberActionFence;
use RadioStamp\Discord\MockGateway;
use RadioStamp\Discord\VoiceRoster;
use RadioStamp\Discord\VoiceState;

$rsVoiceAssertions = 0;
function rsVoiceAssert(bool $condition, string $message): void
{
    global $rsVoiceAssertions; $rsVoiceAssertions++;
    if (!$condition) { throw new RuntimeException('Voice safety assertion failed: ' . $message); }
}
function rsVoiceSame(mixed $expected, mixed $actual, string $message): void { rsVoiceAssert($expected === $actual, $message); }
function rsVoiceActions(MockGateway $mock): array
{
    return array_values(array_filter($mock->actionLog, static fn($action) => in_array($action['method'], ['moveMember', 'disconnectMember', 'syncDecorationRole'], true)));
}
function rsVoiceCode(array $tick): string
{
    foreach ($tick['sessions'] as $session) { if (($session['session_id'] ?? '') === '2026-10-17_0700') { return $session['code']; } }
    return 'not_processed';
}
function rsVoiceFence(MockGateway $mock, string $user = 'person'): MemberActionFence
{
    $state = $mock->voiceState('guild', $user);
    return new MemberActionFence('guild', $user, 'exercise', $state->generation, $state->observedAt);
}

$clock = new FrozenClock('2026-10-17 07:00:00+09:00');
$tracker = new GatewayVoiceTracker($clock);
rsVoiceSame(false, $tracker->roster('guild', 'exercise')->known, 'initial roster is unknown');
rsVoiceSame(false, $tracker->voiceState('guild', 'person')->known, 'initial individual is unknown');
rsVoiceSame(false, $tracker->heartbeatAcknowledged(), 'heartbeat without stream cannot create knowledge');
rsVoiceSame('stream_not_ready', $tracker->onDispatch('GUILD_CREATE', 1, ['id' => 'guild', 'voice_states' => []])['code'], 'snapshot without established stream rejected');
$tracker->onDispatch('READY', 1, ['session_id' => 'fictional-auth-marker-not-retained', 'resume_gateway_url' => 'fictional-resume-marker', 'guilds' => [['id' => 'guild']]]);
rsVoiceSame(false, $tracker->roster('guild', 'exercise')->known, 'READY is not a complete voice snapshot');
rsVoiceSame('complete_snapshot_required', $tracker->onDispatch('VOICE_STATE_UPDATE', 2, ['guild_id' => 'guild', 'user_id' => 'person', 'channel_id' => 'exercise'])['code'], 'individual update cannot bootstrap complete roster');
rsVoiceSame(false, $tracker->roster('guild', 'exercise')->known, 'partial updates remain unknown');
$tracker->onDispatch('GUILD_CREATE', 3, ['id' => 'guild', 'name' => 'fictional-unretained-name', 'token' => 'fictional-unused-token-field', 'voice_states' => [
    ['user_id' => 'person', 'channel_id' => 'exercise', 'session_id' => 'fictional-voice-session-marker', 'member' => ['private_extra' => 'fictional-member-marker']],
    ['user_id' => 'visitor', 'channel_id' => 'exercise'], ['user_id' => 'other-person', 'channel_id' => 'work-room'],
]]);
rsVoiceSame(['person', 'visitor'], $tracker->roster('guild', 'exercise')->memberIds, 'complete snapshot enumerates all target VC occupants');
rsVoiceSame('work-room', $tracker->voiceState('guild', 'other-person')->channelId, 'separate VC retained as separate state');
rsVoiceSame(true, $tracker->voiceState('guild', 'absent-person')->known, 'absent member from complete baseline is known disconnected');
rsVoiceSame(null, $tracker->voiceState('guild', 'absent-person')->channelId, 'known absence distinct from unknown');
$projection = serialize($tracker) . json_encode($tracker->snapshot('guild'), JSON_THROW_ON_ERROR);
foreach (['fictional-auth-marker', 'fictional-resume-marker', 'fictional-unretained-name', 'fictional-unused-token-field', 'fictional-voice-session-marker', 'fictional-member-marker'] as $discarded) {
    rsVoiceAssert(!str_contains($projection, $discarded), 'extra raw frame data is discarded rather than stored');
}
$baselineGeneration = $tracker->snapshot('guild')->generation;
$tracker->onDispatch('VOICE_STATE_UPDATE', 4, ['guild_id' => 'guild', 'user_id' => 'new-person', 'channel_id' => 'exercise']);
$tracker->onDispatch('MESSAGE_CREATE', 5, ['content' => 'fictional-message-not-retained', 'session_id' => 'fictional-other-marker']);
$tracker->onDispatch('VOICE_STATE_UPDATE', 6, ['guild_id' => 'guild', 'user_id' => 'person', 'channel_id' => 'work-room']);
$tracker->onDispatch('VOICE_STATE_UPDATE', 7, ['guild_id' => 'guild', 'user_id' => 'visitor', 'channel_id' => null]);
rsVoiceSame(['new-person'], $tracker->roster('guild', 'exercise')->memberIds, 'ordered join/move/disconnect updates maintain roster');
rsVoiceSame(7, $tracker->snapshot('guild')->sequence, 'unrelated dispatch consumed in global sequence');
rsVoiceAssert($tracker->snapshot('guild')->generation > $baselineGeneration, 'voice changes increment generation');
rsVoiceSame('sequence_gap', $tracker->onDispatch('VOICE_STATE_UPDATE', 9, ['guild_id' => 'guild', 'user_id' => 'person', 'channel_id' => 'exercise'])['code'], 'sequence gap rejected');
rsVoiceSame(false, $tracker->roster('guild', 'exercise')->known, 'gap invalidates complete roster');
$gapGeneration = $tracker->snapshot('guild')->generation;
$tracker->onDispatch('VOICE_STATE_UPDATE', 10, ['guild_id' => 'guild', 'user_id' => 'person', 'channel_id' => 'exercise']);
$tracker->heartbeatAcknowledged();
rsVoiceSame(false, $tracker->roster('guild', 'exercise')->known, 'individual update and heartbeat cannot repair gap');
$tracker->onDispatch('GUILD_CREATE', 11, ['id' => 'guild', 'voice_states' => [['user_id' => 'person', 'channel_id' => 'exercise']]]);
rsVoiceSame(true, $tracker->roster('guild', 'exercise')->known, 'new complete snapshot repairs baseline');
rsVoiceAssert($tracker->snapshot('guild')->generation > $gapGeneration, 'new baseline does not reuse old generation');
rsVoiceSame('sequence_replay_or_reverse', $tracker->onDispatch('VOICE_STATE_UPDATE', 11, ['guild_id' => 'guild', 'user_id' => 'person', 'channel_id' => 'work-room'])['code'], 'duplicate sequence rejected');
rsVoiceSame(false, $tracker->roster('guild', 'exercise')->known, 'duplicate cannot resurrect stale state');
rsVoiceSame('sequence_replay_or_reverse', $tracker->onDispatch('VOICE_STATE_UPDATE', 8, ['guild_id' => 'guild', 'user_id' => 'person', 'channel_id' => 'exercise'])['code'], 'reverse sequence rejected');
$tracker->onDispatch('GUILD_CREATE', 12, ['id' => 'guild', 'voice_states' => []]);
rsVoiceSame(true, $tracker->roster('guild', 'exercise')->known, 'explicit empty complete snapshot is known');
rsVoiceSame([], $tracker->roster('guild', 'exercise')->memberIds, 'empty complete roster has zero occupants');
$tracker->onDispatch('GUILD_DELETE', 13, ['id' => 'guild', 'unavailable' => true]);
rsVoiceSame(false, $tracker->roster('guild', 'exercise')->known, 'unavailable guild invalidated');
$tracker->onDispatch('GUILD_CREATE', 14, ['id' => 'guild']);
rsVoiceSame(false, $tracker->roster('guild', 'exercise')->known, 'missing voice_states is not silently empty');
$tracker->onDispatch('GUILD_CREATE', 15, ['id' => 'guild', 'voice_states' => [['user_id' => 'person', 'channel_id' => 'exercise'], ['user_id' => 'person', 'channel_id' => 'work-room']]]);
rsVoiceSame(false, $tracker->roster('guild', 'exercise')->known, 'ambiguous duplicate users invalidate baseline');
$tracker->onDisconnect();
rsVoiceSame(false, $tracker->roster('guild', 'exercise')->known, 'disconnect invalidates snapshot');
$tracker->onDispatch('RESUMED', 100, ['session_id' => 'fictional-resume-not-retained']);
rsVoiceSame(false, $tracker->roster('guild', 'exercise')->known, 'RESUMED does not restore old voice baseline');
$tracker->onDispatch('VOICE_STATE_UPDATE', 101, ['guild_id' => 'guild', 'user_id' => 'person', 'channel_id' => 'exercise']);
rsVoiceSame(false, $tracker->roster('guild', 'exercise')->known, 'post-resume partial update remains unknown');
$tracker->onDispatch('GUILD_CREATE', 102, ['id' => 'guild', 'voice_states' => [['user_id' => 'person', 'channel_id' => 'exercise']]]);
rsVoiceSame(true, $tracker->roster('guild', 'exercise')->known, 'post-resume full snapshot establishes knowledge');
$tracker->onReconnect();
rsVoiceSame(false, $tracker->roster('guild', 'exercise')->known, 'reconnect invalidates baseline before new READY');
rsVoiceSame(false, $tracker->heartbeatAcknowledged(), 'no active stream after reconnect');
$tracker->onDispatch('READY', 1, []);
$tracker->onDispatch('GUILD_CREATE', 2, ['id' => 'guild', 'voice_states' => [['user_id' => 'person', 'channel_id' => 'exercise']]]);
$quietGeneration = $tracker->snapshot('guild')->generation;
$clock->advance(30); $tracker->heartbeatAcknowledged(); $clock->advance(30);
rsVoiceSame(true, $tracker->roster('guild', 'exercise')->known, 'quiet VC stays known under healthy heartbeat observations');
rsVoiceSame($clock->now()->getTimestamp(), $tracker->roster('guild', 'exercise')->observedAt, 'snapshot observation time is read time, not last voice event');
rsVoiceSame($quietGeneration, $tracker->snapshot('guild')->generation, 'quiet heartbeat does not fabricate a voice transition');
$clock->advance(45);
rsVoiceSame(false, $tracker->roster('guild', 'exercise')->known, 'health timeout invalidates stale projection');
$tracker->heartbeatAcknowledged();
rsVoiceSame(false, $tracker->roster('guild', 'exercise')->known, 'ACK alone cannot recover stale baseline');
$tracker->onDispatch('GUILD_CREATE', 3, ['id' => 'guild', 'voice_states' => [['user_id' => 'person', 'channel_id' => 'exercise']]]);
rsVoiceSame(true, $tracker->roster('guild', 'exercise')->known, 'fresh complete baseline required after health loss');
$clock->advance(-1);
rsVoiceSame(false, $tracker->roster('guild', 'exercise')->known, 'future transport-health time is not trusted');

// Pure in-memory conditional transport: expected source and generation are checked atomically.
$atomicClock = new FrozenClock('2026-10-17 07:15:00+09:00');
$atomic = new MockGateway($atomicClock);
$atomic->setVoiceState('person', 'exercise'); $fence = rsVoiceFence($atomic);
$atomic->beforeConditionalAction = static function (MockGateway $gateway): void { $gateway->setVoiceState('person', 'work-room'); };
rsVoiceSame(false, $atomic->moveMemberIfUnchanged('guild', 'person', 'onsen', $fence, 'race-move'), 'source change between read and mutation blocks move');
rsVoiceSame('work-room', $atomic->voiceState('guild', 'person')->channelId, 'work-room member remains untouched');
rsVoiceSame([], rsVoiceActions($atomic), 'race produced no member mutation');
$atomic->beforeConditionalAction = null; $atomic->setVoiceState('person', 'exercise');
rsVoiceSame(false, $atomic->moveMemberIfUnchanged('guild', 'person', 'onsen', $fence, 'stale-generation'), 'old generation rejected after leaving and returning');
$freshFence = rsVoiceFence($atomic);
rsVoiceSame(true, $atomic->moveMemberIfUnchanged('guild', 'person', 'onsen', $freshFence, 'safe-move'), 'unchanged source and generation accepted only in mock');
$atomic->setVoiceState('person', 'work-room');
rsVoiceSame(true, $atomic->moveMemberIfUnchanged('guild', 'person', 'onsen', $freshFence, 'safe-move'), 'successful key replay returns original success');
rsVoiceSame('work-room', $atomic->voiceState('guild', 'person')->channelId, 'replay does not mutate newly changed source');
$atomic->setVoiceState('visitor', 'exercise'); $visitorFence = rsVoiceFence($atomic, 'visitor');
$atomic->beforeConditionalAction = static function (MockGateway $gateway): void { $gateway->setVoiceState('visitor', 'other-voice'); };
rsVoiceSame(false, $atomic->disconnectMemberIfUnchanged('guild', 'visitor', $visitorFence, 'race-disconnect'), 'source change also blocks disconnect');
rsVoiceSame('other-voice', $atomic->voiceState('guild', 'visitor')->channelId, 'other VC visitor remains connected');
$atomic->beforeConditionalAction = null; $atomic->setVoiceState('visitor', 'exercise'); $visitorFence = rsVoiceFence($atomic, 'visitor');
$atomicClock->advance(6);
rsVoiceSame(false, $atomic->disconnectMemberIfUnchanged('guild', 'visitor', $visitorFence, 'old-fence'), 'old snapshot fence cannot authorize mutation');
$atomic->setVoiceState('visitor', null, false);
rsVoiceSame(false, $atomic->disconnectMemberIfUnchanged('guild', 'visitor', rsVoiceFence($atomic, 'visitor'), 'unknown-fence'), 'unknown individual never authorizes disconnect');
$atomic->setVoiceState('visitor', 'exercise'); $atomic->conditionalMemberActionsSupported = false;
rsVoiceSame(false, $atomic->disconnectMemberIfUnchanged('guild', 'visitor', rsVoiceFence($atomic, 'visitor'), 'unsupported-atomic'), 'unsupported conditional transport refuses mutation');
rsVoiceSame(1, count(rsVoiceActions($atomic)), 'only the single safe mocked move ever occurred');

$raceTracker = new GatewayVoiceTracker($atomicClock);
$raceTracker->onDispatch('READY', 1, []);
$raceTracker->onDispatch('GUILD_CREATE', 2, ['id' => 'guild', 'voice_states' => [['user_id' => 'person', 'channel_id' => 'exercise']]]);
$eventAtomic = new MockGateway($atomicClock, $raceTracker); $eventFence = rsVoiceFence($eventAtomic);
$eventAtomic->beforeConditionalAction = static function () use ($raceTracker): void {
    $raceTracker->onDispatch('VOICE_STATE_UPDATE', 3, ['guild_id' => 'guild', 'user_id' => 'person', 'channel_id' => 'work-room']);
};
rsVoiceSame(false, $eventAtomic->moveMemberIfUnchanged('guild', 'person', 'onsen', $eventFence, 'event-race'), 'ordered event arriving before comparison fences off move');
rsVoiceSame('work-room', $eventAtomic->voiceState('guild', 'person')->channelId, 'event source race never touches work-room');
rsVoiceSame([], rsVoiceActions($eventAtomic), 'event race produces no mutation');
$eventAtomic->beforeConditionalAction = null;
$raceTracker->onDispatch('GUILD_CREATE', 4, ['id' => 'guild', 'voice_states' => [['user_id' => 'person', 'channel_id' => 'exercise']]]);
rsVoiceSame(false, $eventAtomic->moveMemberIfUnchanged('guild', 'person', 'onsen', $eventFence, 'event-baseline-generation'), 'fresh baseline cannot reuse old generation fence');

// Integrated closure uses the capability gate even if an owner turns member_actions_enabled on.
$dir = sys_get_temp_dir() . '/radio-stamp-voice-safety-' . bin2hex(random_bytes(8)); mkdir($dir, 0700);
try {
    $db = new Database($dir . '/application.sqlite');
    $coreClock = new FrozenClock('2026-10-17 07:00:00+09:00');
    $coreMock = new MockGateway($coreClock); $coreMock->conditionalMemberActionsSupported = false;
    $config = ['guild_id' => 'guild', 'voice_channel_id' => 'exercise', 'text_channel_id' => 'guide', 'records_channel_id' => 'records', 'destination_channel_id' => 'onsen', 'member_actions_enabled' => true, 'role_ids' => [1 => 'decoration-only']];
    $core = new RadioService($db, $coreMock, $coreClock, $config);
    $core->planSessions(new DateTimeImmutable('2026-10-17 00:00:00+09:00'), new DateTimeImmutable('2026-10-17 23:59:59+09:00'));
    $coreMock->setVoiceState('person', 'exercise');
    rsVoiceSame('started', $core->start('2026-10-17_0700', 'person', 'safety-start')['code'], 'core starts in mock');
    rsVoiceSame('attended', $core->attend('2026-10-17_0700', 'person', 'safety-attend')['code'], 'core attendance recorded');
    $coreClock->advance(840); $core->tick(); $coreClock->advance(60);
    $coreMock->setRoster('exercise', ['person', 'visitor']); $coreMock->setVoiceState('person', 'exercise'); $coreMock->setVoiceState('visitor', 'exercise');
    rsVoiceSame('member_action_transport_unsafe', rsVoiceCode($core->tick()), 'unsafe transport blocks closure before any member actions');
    rsVoiceSame([], rsVoiceActions($coreMock), 'unsafe capability blocks move, disconnect, and role sync');
    $coreMock->conditionalMemberActionsSupported = true;
    $coreMock->beforeConditionalAction = static function (MockGateway $gateway, MemberActionFence $expected): void {
        $gateway->setVoiceState($expected->userId, $expected->userId === 'person' ? 'work-room' : 'other-voice');
    };
    rsVoiceSame('closure_pending', rsVoiceCode($core->tick()), 'source race leaves closure pending');
    $mutations = array_values(array_filter(rsVoiceActions($coreMock), static fn($a) => in_array($a['method'], ['moveMember', 'disconnectMember'], true)));
    rsVoiceSame([], $mutations, 'core uses fenced actions and never moves or disconnects other VC members');
    rsVoiceSame('work-room', $coreMock->voiceState('guild', 'person')->channelId, 'registered member stays in work-room after race');
    rsVoiceSame('other-voice', $coreMock->voiceState('guild', 'visitor')->channelId, 'unregistered member stays in different VC after race');
    $coreMock->beforeConditionalAction = null;
    rsVoiceSame('closed', rsVoiceCode($core->tick()), 'next observation closes without touching departed members');

    // A live-looking class cannot unlock the boundary by self-reporting atomic capability.
    $claiming = new class implements AtomicMemberGateway {
        public function supportsConditionalMemberActions(): bool { return true; }
        public function voiceState(string $g, string $u): VoiceState { throw new LogicException('must not be called'); }
        public function roster(string $g, string $c): VoiceRoster { throw new LogicException('must not be called'); }
        public function sendMessage(string $c, string $m, string $k, array $components = []): bool { throw new LogicException('must not be called'); }
        public function setRoomOpen(string $c, bool $o, string $k): bool { throw new LogicException('must not be called'); }
        public function moveMember(string $g, string $u, string $d, string $k): bool { throw new LogicException('must not be called'); }
        public function disconnectMember(string $g, string $u, string $k): bool { throw new LogicException('must not be called'); }
        public function syncDecorationRole(string $g, string $u, ?string $r, string $k): bool { throw new LogicException('must not be called'); }
        public function moveMemberIfUnchanged(string $g, string $u, string $d, MemberActionFence $f, string $k): bool { throw new LogicException('must not be called'); }
        public function disconnectMemberIfUnchanged(string $g, string $u, MemberActionFence $f, string $k): bool { throw new LogicException('must not be called'); }
    };
    try { new RadioService($db, $claiming, $coreClock, $config); rsVoiceAssert(false, 'self-claimed atomic live class rejected'); }
    catch (LogicException) { rsVoiceAssert(true, 'self-claimed atomic live class rejected'); }

    // Event-backed observation is usable by the mock; disconnect makes the core stop safely.
    $eventDb = new Database($dir . '/event-application.sqlite');
    $eventClock = new FrozenClock('2026-10-17 07:00:00+09:00');
    $eventTracker = new GatewayVoiceTracker($eventClock, 120);
    $eventTracker->onDispatch('READY', 1, []);
    $eventTracker->onDispatch('GUILD_CREATE', 2, ['id' => 'guild', 'voice_states' => [['user_id' => 'person', 'channel_id' => 'exercise']]]);
    $eventMock = new MockGateway($eventClock, $eventTracker);
    $eventCore = new RadioService($eventDb, $eventMock, $eventClock, $config);
    $eventCore->planSessions(new DateTimeImmutable('2026-10-17 00:00:00+09:00'), new DateTimeImmutable('2026-10-17 23:59:59+09:00'));
    rsVoiceSame('started', $eventCore->start('2026-10-17_0700', 'person', 'event-start')['code'], 'event projection drives start presence');
    rsVoiceSame('attended', $eventCore->attend('2026-10-17_0700', 'person', 'event-attend')['code'], 'event projection drives attendance presence');
    $eventClock->advance(840); $eventTracker->onDispatch('GUILD_CREATE', 3, ['id' => 'guild', 'voice_states' => [['user_id' => 'person', 'channel_id' => 'exercise']]]);
    $eventCore->tick(); $eventClock->advance(60); $eventTracker->onDisconnect();
    rsVoiceSame('voice_roster_unknown', rsVoiceCode($eventCore->tick()), 'disconnected event stream prevents closure');
    $eventMoves = array_values(array_filter(rsVoiceActions($eventMock), static fn($a) => in_array($a['method'], ['moveMember', 'disconnectMember'], true)));
    rsVoiceSame([], $eventMoves, 'unknown event roster cannot authorize member movement');
    $eventTracker->onDispatch('READY', 100, []);
    $eventTracker->onDispatch('GUILD_CREATE', 101, ['id' => 'guild', 'voice_states' => [['user_id' => 'person', 'channel_id' => 'exercise']]]);
    rsVoiceSame('closure_pending', rsVoiceCode($eventCore->tick()), 'mock action success does not fabricate an inbound Gateway event');
    $eventMoves = array_values(array_filter(rsVoiceActions($eventMock), static fn($a) => $a['method'] === 'moveMember'));
    rsVoiceSame(1, count($eventMoves), 'one safe mocked move accepted');
    rsVoiceSame('exercise', $eventTracker->voiceState('guild', 'person')->channelId, 'projection still waits for actual ordered voice event');
    rsVoiceSame('closure_pending', rsVoiceCode($eventCore->tick()), 'repeated tick waits for voice event after action acknowledgement');
    rsVoiceSame(1, count(array_filter(rsVoiceActions($eventMock), static fn($a) => $a['method'] === 'moveMember')), 'waiting for event never duplicates accepted action');
    $eventTracker->onDispatch('VOICE_STATE_UPDATE', 102, ['guild_id' => 'guild', 'user_id' => 'person', 'channel_id' => 'onsen']);
    rsVoiceSame('closed', rsVoiceCode($eventCore->tick()), 'closure completes only once voice event confirms departure');
    echo 'voice safety: ' . $rsVoiceAssertions . " assertions passed; offline reducer and mock-only conditional actions\n";
} finally {
    unset($core, $db, $eventCore, $eventDb); gc_collect_cycles();
    foreach (glob($dir . '/*') ?: [] as $file) { if (is_file($file)) { @unlink($file); } }
    @rmdir($dir);
}
