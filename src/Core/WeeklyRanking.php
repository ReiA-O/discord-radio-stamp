<?php
declare(strict_types=1);
namespace RadioStamp\Core;

use PDO;
use RadioStamp\Discord\RestResult;
use RadioStamp\Discord\TransportOutcome;
use RadioStamp\Discord\WeeklyRankingGateway;

/** Scheduled attendance counts only; this service never changes participation history. */
final class WeeklyRanking
{
    public const MAX_ATTEMPTS = 5;
    private const JSON_FLAGS = JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR;

    public function __construct(private readonly Database $db, private readonly WeeklyRankingGateway $gateway,
        private readonly Clock $clock, private readonly array $config) {}

    /** Most recently completed JST week, never an older backlog or the current week. */
    public static function weekWindow(\DateTimeImmutable $now): array
    {
        $day = $now->setTimezone(new \DateTimeZone('Asia/Tokyo'))->setTime(0, 0, 0);
        $end = $day->modify('-' . ((int)$day->format('N') - 1) . ' days');
        return ['week_start' => $end->modify('-7 days')->getTimestamp(), 'week_end' => $end->getTimestamp(),
            'eligible_at' => $end->getTimestamp() + 7 * 3600, 'expires_at' => $end->modify('+7 days')->getTimestamp()];
    }

    public static function weekKey(string $guild, int $weekStart): string
    {
        if (!self::snowflake($guild) || $weekStart <= 0) { throw new \InvalidArgumentException('weekly_ranking_scope_invalid'); }
        return 'weekly-ranking:' . $guild . ':' . $weekStart;
    }

    public static function partKey(string $weekKey, int $part): string
    {
        if ($part < 1 || preg_match('/\Aweekly-ranking:[1-9][0-9]{16,19}:[1-9][0-9]*\z/D', $weekKey) !== 1) { throw new \InvalidArgumentException('weekly_ranking_part_invalid'); }
        return $weekKey . ':part:' . $part;
    }

    /** The PK of attendance already deduplicates (session,user); DISTINCT is explicit. */
    public function snapshot(int $weekStart, int $weekEnd): array
    {
        self::validWeek($weekStart, $weekEnd);
        $query = $this->db->pdo()->prepare('SELECT a.user_id,COUNT(DISTINCT a.session_id) AS attendance_count FROM attendance a JOIN sessions s ON s.id=a.session_id WHERE s.scheduled_at>=? AND s.scheduled_at<? GROUP BY a.user_id HAVING COUNT(DISTINCT a.session_id)>0 ORDER BY attendance_count DESC,LENGTH(a.user_id) ASC,a.user_id ASC');
        $query->execute([$weekStart, $weekEnd]); $rows = $query->fetchAll(PDO::FETCH_ASSOC);
        $entries = []; $rank = 0; $previous = null;
        foreach ($rows as $index => $row) {
            $count = (int)$row['attendance_count'];
            if (!self::snowflake($row['user_id'] ?? null) || $count < 1) { throw new \RuntimeException('weekly_ranking_history_invalid'); }
            if ($count !== $previous) { $rank = $index + 1; $previous = $count; }
            if ($rank > 5) { break; }
            $entries[] = ['user_id' => $row['user_id'], 'count' => $count, 'rank' => $rank];
        }
        return ['week_start' => $weekStart, 'week_end' => $weekEnd, 'entries' => $entries, 'total_participants' => count($rows)];
    }

    /** Keep every qualifying tie, with stable numeric-ID order from the snapshot. */
    public static function parts(array $snapshot): array
    {
        self::validWeek($snapshot['week_start'] ?? 0, $snapshot['week_end'] ?? 0);
        if (!is_array($snapshot['entries'] ?? null) || !array_is_list($snapshot['entries'])) { throw new \InvalidArgumentException('weekly_ranking_snapshot_invalid'); }
        $groups = []; $lines = []; $users = []; $length = 0; $seen = [];
        foreach ($snapshot['entries'] as $entry) {
            if (!is_array($entry) || !self::snowflake($entry['user_id'] ?? null) || isset($seen[$entry['user_id']]) || !is_int($entry['rank'] ?? null) || $entry['rank'] < 1 || $entry['rank'] > 5 || !is_int($entry['count'] ?? null) || $entry['count'] < 1) { throw new \InvalidArgumentException('weekly_ranking_snapshot_invalid'); }
            $seen[$entry['user_id']] = true;
            $line = $entry['rank'] . '位 <@' . $entry['user_id'] . '>  ' . $entry['count'] . '回'; $lineLength = self::contentLength($line) + 1;
            if ($lineLength > 1800) { throw new \InvalidArgumentException('weekly_ranking_snapshot_invalid'); }
            if ($lines !== [] && (count($users) >= 100 || $length + $lineLength > 1800)) { $groups[] = [$lines, $users]; $lines = []; $users = []; $length = 0; }
            $lines[] = $line; $users[] = $entry['user_id']; $length += $lineLength;
        }
        if ($lines !== []) { $groups[] = [$lines, $users]; }
        $zone = new \DateTimeZone('Asia/Tokyo');
        $start = (new \DateTimeImmutable('@' . $snapshot['week_start']))->setTimezone($zone)->format('Y/m/d');
        $end = (new \DateTimeImmutable('@' . ($snapshot['week_end'] - 1)))->setTimezone($zone)->format('Y/m/d');
        $parts = []; $total = count($groups);
        foreach ($groups as $index => [$partLines, $partUsers]) {
            $payload = ['content' => '🏆 先週のラジオ体操参加回数ランキング' . "\n" . $start . '〜' . $end . '（JST）' . "\n" . ($index + 1) . '/' . $total . "\n" . implode("\n", $partLines),
                'tts' => false, 'allowed_mentions' => ['parse' => [], 'users' => $partUsers, 'roles' => [], 'replied_user' => false]];
            if (!self::validPayload($payload)) { throw new \RuntimeException('weekly_ranking_payload_invalid'); }
            $parts[] = $payload;
        }
        return $parts;
    }

    /** Shared transport validator: only explicitly listed user mentions are permitted. */
    public static function validPayload(array $payload): bool
    {
        if (array_keys($payload) !== ['content', 'tts', 'allowed_mentions'] || !is_string($payload['content']) || $payload['content'] === '' || ($payload['tts'] ?? null) !== false || !is_array($payload['allowed_mentions'] ?? null)) { return false; }
        try { if (self::contentLength($payload['content']) > 2000) { return false; } } catch (\Throwable) { return false; }
        $mentions = $payload['allowed_mentions'];
        if (array_keys($mentions) !== ['parse', 'users', 'roles', 'replied_user'] || $mentions['parse'] !== [] || $mentions['roles'] !== [] || $mentions['replied_user'] !== false || !is_array($mentions['users']) || !array_is_list($mentions['users']) || count($mentions['users']) < 1 || count($mentions['users']) > 100 || count(array_unique($mentions['users'])) !== count($mentions['users'])) { return false; }
        foreach ($mentions['users'] as $user) { if (!self::snowflake($user)) { return false; } }
        if (preg_match('/@everyone|@here|<@&|<@!/u', $payload['content']) === 1) { return false; }
        $found = []; preg_match_all('/<@([1-9][0-9]{16,19})>/u', $payload['content'], $found);
        return ($found[1] ?? []) === $mentions['users'];
    }

    /** Discord's UTF-16 boundary is treated conservatively, including astral emoji. */
    public static function contentLength(string $content): int
    {
        $characters = preg_match_all('/./us', $content); $astral = preg_match_all('/[\x{10000}-\x{10FFFF}]/u', $content);
        if ($characters === false || $astral === false) { throw new \InvalidArgumentException('weekly_ranking_text_invalid'); }
        return $characters + $astral;
    }

    /** A tick claims at most one part and releases the DB transaction before HTTP. */
    public function tick(?\Closure $guard = null): array
    {
        if (($this->config['weekly_ranking_enabled'] ?? false) !== true) { return self::result(true, 'disabled', 'weekly_ranking_disabled'); }
        if (!self::snowflake($this->config['guild_id'] ?? null) || !self::snowflake($this->config['records_channel_id'] ?? null)) { return self::result(false, 'blocked', 'weekly_ranking_configuration_unavailable'); }
        if ($this->db->inTransaction() || !self::permitted($guard)) { return self::result(true, 'deferred', 'weekly_ranking_deferred'); }
        $window = self::weekWindow($this->clock->now()); $now = $this->clock->now()->getTimestamp();
        if ($now < $window['eligible_at'] || $now >= $window['expires_at']) { return self::result(true, 'not_due', 'weekly_ranking_not_due'); }
        try { $selection = $this->db->transaction(fn(PDO $pdo): array => $this->claim($pdo, $window, $guard)); }
        catch (\Throwable) { return self::result(false, 'blocked', 'weekly_ranking_unavailable'); }
        if (!isset($selection['claim'])) { return $selection; }
        $claim = $selection['claim'];
        if (!self::permitted($guard)) {
            try { $this->releaseUnstarted($claim); } catch (\Throwable) { return self::result(false, 'sending', 'weekly_ranking_held'); }
            return self::result(true, 'deferred', 'weekly_ranking_deferred');
        }
        if ($this->clock->now()->getTimestamp() >= $claim['expires_at']) {
            try { $this->finish($claim, 'expired', 'not_started', null, $claim['expires_at']); } catch (\Throwable) { $this->hold($claim); }
            return self::result(false, 'expired', 'weekly_ranking_expired');
        }
        try { $response = $this->gateway->sendWeeklyRankingResult($claim['channel_id'], $claim['payload'], $claim['part_key']); }
        catch (\Throwable) { $this->hold($claim); return self::result(false, 'hold', 'weekly_ranking_held'); }
        $message = $response->data['id'] ?? null;
        if ($response->ok && $response->outcome === TransportOutcome::Succeeded && self::snowflake($message) && (!isset($response->data['channel_id']) || $response->data['channel_id'] === $claim['channel_id'])) {
            try { $complete = $this->finish($claim, 'sent', 'succeeded', $message, 0); }
            catch (\Throwable) { $this->hold($claim); return self::result(false, 'hold', 'weekly_ranking_held'); }
            return self::result(true, $complete ? 'sent' : 'pending', 'weekly_ranking_part_sent', 1);
        }
        if (!$response->ok && $response->outcome->retryable()) {
            $now = $this->clock->now()->getTimestamp(); $remaining = max(0, $claim['expires_at'] - $now);
            $retry = is_finite($response->retryAfter) ? max(0.0, $response->retryAfter) : (float)$remaining;
            $delay = max(5 * (2 ** ($claim['attempts'] - 1)), (int)ceil(min($retry, (float)$remaining)));
            $available = $now + $delay;
            $state = $claim['attempts'] >= self::MAX_ATTEMPTS ? 'failed' : ($available >= $claim['expires_at'] ? 'expired' : 'pending');
            try { $this->finish($claim, $state, $response->outcome->value, null, $available); }
            catch (\Throwable) { $this->hold($claim); return self::result(false, 'hold', 'weekly_ranking_held'); }
            return self::result($state === 'pending', $state, $state === 'pending' ? 'weekly_ranking_retry_pending' : ($state === 'failed' ? 'weekly_ranking_retry_exhausted' : 'weekly_ranking_expired'));
        }
        $this->hold($claim);
        return self::result(false, 'hold', 'weekly_ranking_held');
    }

    private function claim(PDO $pdo, array $window, ?\Closure $guard): array
    {
        $now = $this->clock->now()->getTimestamp();
        if (!self::permitted($guard) || $now < $window['eligible_at'] || $now >= $window['expires_at']) { return self::result(true, 'deferred', 'weekly_ranking_deferred'); }
        $weekKey = self::weekKey($this->config['guild_id'], $window['week_start']);
        $select = $pdo->prepare('SELECT * FROM weekly_ranking_weeks WHERE week_key=?'); $select->execute([$weekKey]); $week = $select->fetch();
        if ($week === false) {
            $snapshot = $this->snapshot($window['week_start'], $window['week_end']); $parts = self::parts($snapshot); $count = count($parts);
            $pdo->prepare('INSERT INTO weekly_ranking_weeks(week_key,guild_id,week_start,week_end,eligible_at,expires_at,channel_id,snapshot_json,part_count,status,created_at) VALUES(?,?,?,?,?,?,?,?,?,?,?)')
                ->execute([$weekKey, $this->config['guild_id'], $window['week_start'], $window['week_end'], $window['eligible_at'], $window['expires_at'], $this->config['records_channel_id'], json_encode($snapshot, self::JSON_FLAGS), $count, $count === 0 ? 'skipped' : 'pending', $now]);
            $insert = $pdo->prepare("INSERT INTO weekly_ranking_parts(part_key,week_key,part_no,payload_json,status,available_at) VALUES(?,?,?,?,'pending',?)");
            foreach ($parts as $index => $payload) { $insert->execute([self::partKey($weekKey, $index + 1), $weekKey, $index + 1, json_encode($payload, self::JSON_FLAGS), $window['eligible_at']]); }
            $select->execute([$weekKey]); $week = $select->fetch();
        }
        if ($week['guild_id'] !== $this->config['guild_id'] || (int)$week['week_start'] !== $window['week_start'] || (int)$week['week_end'] !== $window['week_end'] || (int)$week['eligible_at'] !== $window['eligible_at'] || (int)$week['expires_at'] !== $window['expires_at'] || !self::snowflake($week['channel_id'])) { throw new \RuntimeException('weekly_ranking_ledger_invalid'); }
        if ($week['status'] !== 'pending') { return self::result(in_array($week['status'], ['sent', 'skipped'], true), $week['status'], $week['status'] === 'sent' ? 'weekly_ranking_complete' : ($week['status'] === 'skipped' ? 'weekly_ranking_skipped' : 'weekly_ranking_held')); }
        $selectPart = $pdo->prepare("SELECT * FROM weekly_ranking_parts WHERE week_key=? AND status<>'sent' ORDER BY part_no LIMIT 1"); $selectPart->execute([$weekKey]); $part = $selectPart->fetch();
        if ($part === false) { throw new \RuntimeException('weekly_ranking_ledger_invalid'); }
        // A crashed or concurrent sender is never reclassified as definitely unsent.
        if ($part['status'] !== 'pending') { return self::result(false, $part['status'], 'weekly_ranking_held'); }
        if ((int)$part['available_at'] > $now) { return self::result(true, 'pending', 'weekly_ranking_retry_wait'); }
        if ((int)$part['attempts'] >= self::MAX_ATTEMPTS) { throw new \RuntimeException('weekly_ranking_attempts_exhausted'); }
        $payload = json_decode($part['payload_json'], true, 16, JSON_THROW_ON_ERROR);
        $saved = json_decode($week['snapshot_json'], true, 16, JSON_THROW_ON_ERROR); $expectedParts = self::parts($saved); $number = (int)$part['part_no'];
        if ($number < 1 || $number > (int)$week['part_count'] || count($expectedParts) !== (int)$week['part_count'] || $part['part_key'] !== self::partKey($weekKey, $number) || !is_array($payload) || !self::validPayload($payload) || $part['payload_json'] !== json_encode($expectedParts[$number - 1], self::JSON_FLAGS)) { throw new \RuntimeException('weekly_ranking_ledger_invalid'); }
        if (!self::permitted($guard)) { return self::result(true, 'deferred', 'weekly_ranking_deferred'); }
        $owner = bin2hex(random_bytes(16));
        $update = $pdo->prepare("UPDATE weekly_ranking_parts SET status='sending',attempts=attempts+1,claim_owner=?,attempted_at=?,outcome=NULL WHERE part_key=? AND status='pending' AND available_at<=? AND attempts<?");
        $update->execute([$owner, $now, $part['part_key'], $now, self::MAX_ATTEMPTS]);
        if ($update->rowCount() !== 1) { return self::result(true, 'deferred', 'weekly_ranking_deferred'); }
        return ['claim' => ['week_key' => $weekKey, 'part_key' => $part['part_key'], 'claim_owner' => $owner, 'attempts' => (int)$part['attempts'] + 1,
            'channel_id' => $week['channel_id'], 'expires_at' => (int)$week['expires_at'], 'payload' => $payload]];
    }

    private function finish(array $claim, string $state, string $outcome, ?string $message, int $available): bool
    {
        return $this->db->transaction(function(PDO $pdo) use ($claim, $state, $outcome, $message, $available): bool {
            $update = $pdo->prepare("UPDATE weekly_ranking_parts SET status=?,available_at=?,claim_owner=NULL,sent_at=?,message_id=?,outcome=? WHERE part_key=? AND status='sending' AND claim_owner=?");
            $update->execute([$state, $available, $state === 'sent' ? $this->clock->now()->getTimestamp() : null, $message, $outcome, $claim['part_key'], $claim['claim_owner']]);
            if ($update->rowCount() !== 1) { throw new \RuntimeException('weekly_ranking_claim_changed'); }
            $left = $pdo->prepare("SELECT COUNT(*) FROM weekly_ranking_parts WHERE week_key=? AND status<>'sent'"); $left->execute([$claim['week_key']]); $complete = (int)$left->fetchColumn() === 0;
            $weekState = $complete ? 'sent' : (in_array($state, ['failed', 'expired'], true) ? $state : 'pending');
            $weekUpdate = $pdo->prepare("UPDATE weekly_ranking_weeks SET status=? WHERE week_key=? AND status='pending'");
            $weekUpdate->execute([$weekState, $claim['week_key']]);
            if ($weekUpdate->rowCount() !== 1) { throw new \RuntimeException('weekly_ranking_week_changed'); }
            return $complete;
        });
    }

    private function releaseUnstarted(array $claim): void
    {
        $this->db->transaction(function(PDO $pdo) use ($claim): void {
            $query = $pdo->prepare("UPDATE weekly_ranking_parts SET status='pending',attempts=attempts-1,claim_owner=NULL,attempted_at=NULL,outcome='not_started' WHERE part_key=? AND status='sending' AND claim_owner=?");
            $query->execute([$claim['part_key'], $claim['claim_owner']]); if ($query->rowCount() !== 1) { throw new \RuntimeException('weekly_ranking_claim_changed'); }
        });
    }

    private function hold(array $claim): void
    {
        try {
            $this->db->transaction(function(PDO $pdo) use ($claim): void {
                $pdo->prepare("UPDATE weekly_ranking_parts SET status='hold',outcome='uncertain' WHERE part_key=? AND status='sending' AND claim_owner=?")->execute([$claim['part_key'], $claim['claim_owner']]);
                $pdo->prepare("UPDATE weekly_ranking_weeks SET status='hold' WHERE week_key=? AND status NOT IN ('sent','skipped')")->execute([$claim['week_key']]);
            });
        } catch (\Throwable) { /* Retained sending claim blocks replay when persistence itself is unavailable. */ }
    }

    private static function validWeek(int $start, int $end): void
    {
        $date = (new \DateTimeImmutable('@' . $start))->setTimezone(new \DateTimeZone('Asia/Tokyo'));
        if ($start <= 0 || $end - $start !== 7 * 86400 || $date->format('N H:i:s') !== '1 00:00:00') { throw new \InvalidArgumentException('weekly_ranking_week_invalid'); }
    }

    private static function permitted(?\Closure $guard): bool { try { return $guard === null || $guard() === true; } catch (\Throwable) { return false; } }
    private static function snowflake(mixed $value): bool { return is_string($value) && preg_match('/\A[1-9][0-9]{16,19}\z/D', $value) === 1; }
    private static function result(bool $ok, string $state, string $code, int $sent = 0): array { return ['ok' => $ok, 'state' => $state, 'code' => $code, 'sent' => $sent]; }
}
