<?php
declare(strict_types=1);
require dirname(__DIR__) . '/bootstrap.php';

use RadioStamp\Core\Database;
use RadioStamp\Core\FrozenClock;
use RadioStamp\Core\RadioService;
use RadioStamp\Discord\MockGateway;

// In-memory, fictional users and destination. No configuration or credentials required.
if (PHP_SAPI !== 'cli') { http_response_code(503); exit; }
$config = require dirname(__DIR__) . '/config/admin-validation.example.php';
$config['member_actions_enabled'] = true; // MockGateway is the only accepted transport.
$config['participant_commands_enabled'] = true;
$config['destination_channel_id'] = '900000000000000099'; // Fictional; never a real move target.
$clock = new FrozenClock('2026-10-17T03:30:00+09:00');
$db = new Database(':memory:');
$gateway = new MockGateway($clock);
$service = new RadioService($db, $gateway, $clock, $config);
$session = '2026-10-17_0400';
$attendee = '900000000000000001';
$unregistered = '900000000000000002';
$service->tick();
$clock->set('2026-10-17T04:08:00+09:00');
$gateway->setVoiceState($attendee, $config['voice_channel_id']);
$started = $service->openAttendance($attendee, 'demo-post-exercise-command');
$attended = $service->attend($session, $attendee, 'demo-attend');
$replayed = $service->attend($session, $attendee, 'demo-attend');
$clock->set('2026-10-17T04:17:00+09:00');
$warning = $service->tick();
$clock->set('2026-10-17T04:18:00+09:00');
$gateway->setRoster($config['voice_channel_id'], [$attendee, $unregistered]);
$gateway->setVoiceState($attendee, $config['voice_channel_id']);
$gateway->setVoiceState($unregistered, $config['voice_channel_id']);
$closed = $service->tick();
$card = $service->cardData($attendee, '検証用の架空参加者', '2026-10');
$slotStatement = $db->pdo()->prepare('SELECT id FROM sessions WHERE count_date=? ORDER BY scheduled_at');
$slotStatement->execute(['2026-10-17']);
$slots = $slotStatement->fetchAll(PDO::FETCH_COLUMN);
$reminders = array_values(array_filter($gateway->actionLog, static fn(array $a): bool =>
    $a['method'] === 'sendMessage' && $a['ok'] && str_contains($a['content'], 'あと30分でラジオ体操だよー！⚡')));
$moves = array_values(array_filter($gateway->actionLog, static fn(array $a): bool => $a['method'] === 'moveMember' && $a['ok']));
$disconnects = array_values(array_filter($gateway->actionLog, static fn(array $a): bool => $a['method'] === 'disconnectMember' && $a['ok']));
$statement = $db->pdo()->prepare('SELECT status,warning_sent_at,closes_at FROM sessions WHERE id=?');
$statement->execute([$session]);
$state = $statement->fetch();
if (!$started['ok'] || !$attended['ok'] || $attended['stamp'] !== 'コンデンサ' || $replayed !== $attended || $card['total_count'] !== 1 ||
    count($moves) !== 0 || count($disconnects) !== 2 ||
    array_column($disconnects,'user_id') !== [$attendee,$unregistered] || $state['status'] !== 'closed' ||
    $disconnects[0]['at'] - (int)$state['warning_sent_at'] < 60 ||
    $slots !== ['2026-10-17_0000','2026-10-17_0400','2026-10-17_0700','2026-10-17_1230','2026-10-17_2200'] ||
    count($reminders) !== 1 || $reminders[0]['channel_id'] !== $config['text_channel_id'] ||
    !str_contains($reminders[0]['content'], '04:00') ||
    str_contains($reminders[0]['content'], 'https://discord.com/channels/') ||
    ($reminders[0]['allowed_mentions']['parse'] ?? null) !== []) {
    fwrite(STDERR, "Local mock demonstration failed; no live system was contacted.\n");
    exit(1);
}
echo json_encode([
    'mode' => 'mock_only',
    'data' => 'fictional_users_and_destination',
    'post_exercise_receipt' => $started,
    'attendance' => $attended,
    'same_interaction_replayed_once' => true,
    'planned_slots_jst' => $slots,
    'opening_reminder_without_mentions' => true,
    'card_summary' => array_intersect_key($card, array_flip(['month','month_count','total_count','month_days','total_days','days','latest_title','awards'])),
    'session_status' => $state['status'],
    'warning_before_member_action_seconds' => $disconnects[0]['at'] - (int)$state['warning_sent_at'],
    'mock_actions' => $gateway->actionLog,
], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) . "\n";
