<?php
declare(strict_types=1);
require_once __DIR__.'/artifact_directory.php';
require dirname(__DIR__) . '/bootstrap.php';

use RadioStamp\Core\FrozenClock;
use RadioStamp\Validation\OneJoin\Trial;
use RadioStamp\Validation\OneJoin\Window;

$windowAssertions = 0;
function windowSame(mixed $expected, mixed $actual, string $description): void
{
    global $windowAssertions; ++$windowAssertions;
    if ($expected !== $actual) { throw new RuntimeException('Window assertion failed: ' . $description); }
}

// Fictional operational timestamps. No adapters, credentials or live calls.
$setup = 1900000000;
windowSame(3600, Window::OVERALL_SECONDS, 'entire preparation, waiting, trial and cleanup fit one hour');
windowSame(900, Window::OWNER_SECONDS, 'human gets at least fifteen minutes from actual send');
windowSame(2100, Window::INVITE_RESERVE, 'human, relay, verification and cleanup have distinct reserves');
windowSame(null, Window::plan(0, $setup), 'missing setup origin rejected');
windowSame(null, Window::plan($setup, $setup - 1), 'invitation cannot precede preparation');
windowSame(null, Window::plan(PHP_INT_MAX, PHP_INT_MAX), 'integer overflow rejected');

foreach ([0, 1, 60, 899, 1200, 1499, 1500] as $inviteDelay) {
    $invite = $setup + $inviteDelay;
    $plan = Window::plan($setup, $invite);
    windowSame($setup + 3600, $plan['overall_deadline_at'], 'actual invitation delay never extends global deadline');
    windowSame($setup + 1500, $plan['latest_invitation_at'], 'preparation may consume at most twenty-five minutes');
    windowSame($invite + 900, $plan['owner_reply_deadline_at'], 'human deadline starts at parent actual send');
    windowSame($invite + 1200, $plan['relay_deadline_at'], 'five minutes reserved for relay after full human window');
    windowSame($setup + 3000, $plan['verification_deadline_at'], 'ten minutes always reserved for restoration and cleanup');
    foreach ([0, 60, 899, 900] as $humanDelay) {
        foreach ([0, 60, 300] as $relayDelay) {
            $reply = $invite + $humanDelay; $activate = $reply + $relayDelay;
            $activation = Window::activation($setup, $invite, $reply, $activate);
            windowSame(true, Trial::validActivation($activation), 'early or final-second human reply and delayed delivery accepted');
            windowSame($setup + 3000, $activation['expires_at'], 'activation expiry never moves to A plus one hour');
            windowSame(true, Trial::active($activation, new FrozenClock('@' . $activate)), 'valid trial can start after owner reply');
            windowSame(false, Trial::active($activation, new FrozenClock('@' . ($activate - 1))), 'future activation cannot run');
            windowSame(true, Trial::active($activation, new FrozenClock('@' . ($setup + 2999))), 'final admissible second accepted');
            windowSame(false, Trial::active($activation, new FrozenClock('@' . ($setup + 3000))), 'verification deadline itself refuses buttons');
            windowSame(false, Trial::active($activation, new FrozenClock('@' . ($setup + 3600))), 'overall deadline cannot reopen trial');
        }
    }
}
windowSame(null, Window::plan($setup, $setup + 1501), 'one second too much preparation means do not invite');
windowSame(null, Window::plan($setup, $setup + 1800), 'late parent send is rejected even when child was ready earlier');
$earlierSend = Window::plan($setup, $setup + 120);
$laterSend = Window::plan($setup, $setup + 180);
windowSame(60, $laterSend['owner_reply_deadline_at'] - $earlierSend['owner_reply_deadline_at'], 'parent send latency does not consume the human fifteen minutes');
windowSame($earlierSend['overall_deadline_at'], $laterSend['overall_deadline_at'], 'human clock moves but overall clock does not');

$valid = Window::activation($setup, $setup + 1500, $setup + 2400, $setup + 2700);
foreach (['preparation_started_at','invitation_sent_at','owner_join_reply_at','starts_at','expires_at'] as $field) {
    foreach ([0, -1, (string)$valid[$field], (float)$valid[$field], null] as $invalid) {
        $bad = $valid; $bad[$field] = $invalid;
        windowSame(false, Trial::validActivation($bad), 'missing or non-integer timestamps cannot invent readiness');
    }
}
foreach ([
    ['invitation_sent_at', $setup + 1501],
    ['owner_join_reply_at', $setup + 1499],
    ['owner_join_reply_at', $setup + 2401],
    ['starts_at', $setup + 2399],
    ['starts_at', $setup + 2701],
    ['expires_at', $setup + 3001],
    ['expires_at', $setup + 3600],
    ['expires_at', $valid['starts_at'] + 3600],
    ['enabled', false],
] as [$field, $value]) {
    $bad = $valid; $bad[$field] = $value;
    windowSame(false, Trial::validActivation($bad), 'forged, late or extended activation rejected');
}
$extra = $valid; $extra['child_ready_at'] = $setup;
windowSame(false, Trial::validActivation($extra), 'child-ready field cannot replace authoritative parent send');
windowSame(false, Trial::validActivation(['enabled'=>true,'starts_at'=>$setup,'expires_at'=>$setup+3600]), 'one-hour legacy activation lacks human-budget proof');
try { Window::activation($setup, $setup + 1501, $setup + 1502, $setup + 1503); windowSame(true, false, 'unsafe generator must refuse'); }
catch (InvalidArgumentException $error) { windowSame('trial_window_invalid', $error->getMessage(), 'unsafe generator emits fixed classification only'); }

windowSame('inside', Trial::next('check'), 'v3 begins with confirmed live presence, no repeated outside step');
windowSame(false, Trial::expected('inside', 'voice_unknown', ['attendance'=>0,'total'=>0,'month'=>0,'awards'=>0,'effects'=>0], false), '404 safe refusal cannot pass positive trial');
windowSame(false, Trial::expected('outside', 'voice_unknown', ['attendance'=>0,'total'=>0,'month'=>0,'awards'=>0,'effects'=>0], false), 'removed outside step cannot advance v3');
echo 'One-join window: ' . $windowAssertions . " assertions passed (fictional timestamps only; no live calls).\n";
