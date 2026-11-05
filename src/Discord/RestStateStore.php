<?php
declare(strict_types=1);
namespace RadioStamp\Discord;

use PDO;
use RadioStamp\Core\Database;

/** Use the private application SQLite connection; this stores no credentials or payloads. */
final class RestStateStore
{
    /** Cap for bucket resets and unusable guidance; Discord's ordinary limits reset well within an hour. */
    private const MAX_COOLDOWN = 3600.0;
    /**
     * A valid, finite 429 retry interval is honoured in full: no request is sent before it ends (Discord's rate-limit rules).
     * Each limit row has a companion "seen:" row holding when it was observed, so a clock stepped back is detected
     * by seen>now and the wait is re-based to the full interval from now instead of being dropped or stretched.
     * Rows without a companion were written by the previous release, which never stored more than a day ahead.
     */
    private const LEGACY_HORIZON = 86400.0;
    private const SEEN_PREFIX = 'seen:';
    /** Rows written by this release; unprefixed rows from older releases are never read and are purged on write. */
    private const KEY_PREFIX = 'v2:';
    /**
     * A finite Retry-After beyond a week is malformed guidance, not a plausible interval: like a missing value it is
     * not adopted and only pauses its own route for INVALID_RETRY_PAUSE, never global (a bad 429 must not stop the bot).
     */
    public const MAX_VALID_RETRY_AFTER = 604800.0;
    public const INVALID_RETRY_PAUSE = 60.0;
    /**
     * Interaction webhook endpoints are not bound by the bot's global rate limit, and Discord limits them per token,
     * so their limits are kept per hash(token): one user's long Retry-After never holds back replies to others.
     */
    private const INTERACTION_ROUTES = ['interaction_edit'];
    private const TOKEN_ROUTE_PREFIX = 'route:interaction_edit:';
    /** A probe is held at most this long (request timeout plus margin); the next worker may probe after it. */
    private const PROBE_HOLD = 15.0;
    private const PROBE_PREFIX = 'probe:';
    public static function openExisting(PDO $pdo):self{return new self($pdo,false);}

    /** Structural preflight only; never creates tables, changes journals, or seeds state. */
    public static function requireExistingSchema(PDO $pdo):void
    {
        Database::requireTable($pdo,'discord_rest_limits',['limit_key'=>['TEXT',0,null,1],'until_at'=>['REAL',1,null,0]]);
        Database::requireTable($pdo,'discord_rest_routes',['route_key'=>['TEXT',0,null,1],'bucket_key'=>['TEXT',1,null,0]]);
        Database::requireTable($pdo,'discord_rest_commands',['command_key'=>['TEXT',0,null,1],'attempts'=>['INTEGER',1,'0',0],'state'=>['TEXT',1,'pending',0],'lease_until'=>['REAL',1,'0',0],'retry_at'=>['REAL',1,'0',0]]);
    }

    public function __construct(private readonly PDO $pdo,bool $initializeStorage=true)
    {
        if(!$initializeStorage){
            self::requireExistingSchema($pdo);
            return;
        }
        $pdo->exec('CREATE TABLE IF NOT EXISTS discord_rest_limits (limit_key TEXT PRIMARY KEY, until_at REAL NOT NULL)');
        $pdo->exec('CREATE TABLE IF NOT EXISTS discord_rest_routes (route_key TEXT PRIMARY KEY, bucket_key TEXT NOT NULL)');
        $pdo->exec("CREATE TABLE IF NOT EXISTS discord_rest_commands (command_key TEXT PRIMARY KEY, attempts INTEGER NOT NULL DEFAULT 0, state TEXT NOT NULL DEFAULT 'pending', lease_until REAL NOT NULL DEFAULT 0, retry_at REAL NOT NULL DEFAULT 0)");
    }
    /** $major is the interaction token for interaction routes; only its hash is stored. Other routes ignore it here. */
    public function cooldown(string $route, float $now, string $major = ''): float
    {
        // Older releases' rows have no prefix, so a day-long legacy row can never come back into range as time passes.
        $query = $this->pdo->prepare('SELECT MAX(' . self::remainingSql('l.until_at', 's.until_at') . ') FROM discord_rest_limits l LEFT JOIN discord_rest_limits s ON s.limit_key=? || substr(l.limit_key,?) WHERE l.limit_key IN (?, ?, ? || COALESCE((SELECT bucket_key FROM discord_rest_routes WHERE route_key=?), ?))');
        $query->execute([$now + self::LEGACY_HORIZON, $now, $now, $now, self::KEY_PREFIX . self::SEEN_PREFIX, strlen(self::KEY_PREFIX) + 1, self::KEY_PREFIX . (in_array($route, self::INTERACTION_ROUTES, true) ? 'none' : 'global'), self::KEY_PREFIX . 'route:' . self::routeKey($route, $major), self::KEY_PREFIX, $route, 'none']);
        return max(0.0, (float) $query->fetchColumn());
    }
    public function observe(string $route, string $major, array $headers, int $status, float $retryAfter, bool $global, float $now): void
    {
        $interaction = in_array($route, self::INTERACTION_ROUTES, true);
        $bucket = 'route:' . self::routeKey($route, $major);
        // A learned bucket is mapped per route name, which interaction tokens share, so token routes keep only their own row.
        if (!$interaction && isset($headers['x-ratelimit-bucket']) && is_string($headers['x-ratelimit-bucket']) && preg_match('/\A[A-Za-z0-9._-]{1,128}\z/D', $headers['x-ratelimit-bucket'])) {
            $bucket = 'bucket:' . hash('sha256', $major . ':' . $headers['x-ratelimit-bucket']);
            $map = $this->pdo->prepare('INSERT INTO discord_rest_routes(route_key,bucket_key) VALUES (?,?) ON CONFLICT(route_key) DO UPDATE SET bucket_key=excluded.bucket_key');
            $map->execute([$route, $bucket]);
        }
        $reset = is_numeric($headers['x-ratelimit-reset-after'] ?? null) && is_finite((float) $headers['x-ratelimit-reset-after']) && (float) $headers['x-ratelimit-reset-after'] >= 0 ? (float) $headers['x-ratelimit-reset-after'] : 5.0;
        if (($headers['x-ratelimit-remaining'] ?? null) === '0' && $reset > 0) { $this->putLimit($bucket, $this->until($now, $reset), $now); }
        if ($status === 429) {
            $malformed = is_finite($retryAfter) && $retryAfter > self::MAX_VALID_RETRY_AFTER;
            // An interaction route never writes the bot-wide global limit it is exempt from; malformed guidance is never global.
            $global = $global && !$interaction && !$malformed;
            // A valid Retry-After is honoured in full, interaction routes included; only non-finite input falls back to the one-hour cap.
            $retryUntil = $this->until($now, $malformed ? self::INVALID_RETRY_PAUSE : max(0.25, $retryAfter), INF);
            $this->putLimit($global ? 'global' : $bucket, $retryUntil, $now);
            // Route fallback protects requests before a bucket has been learned.
            $this->putLimit('route:' . self::routeKey($route, $major), $retryUntil, $now);
        }
    }
    private static function routeKey(string $route, string $major): string { return in_array($route, self::INTERACTION_ROUTES, true) ? $route . ':' . hash('sha256', $major) : $route; }
    private function until(float $now, float $duration, float $cap = self::MAX_COOLDOWN): float { return $now + (is_finite($duration) ? max(0.0, min($cap, $duration)) : self::MAX_COOLDOWN); }
    /**
     * Seconds a row still applies: a stepped-back clock (seen>now) keeps the full observed interval from now, never more;
     * a row without a seen time (previous release) is ignored beyond its one-day horizon. Binds now+horizon, now, now, now.
     */
    private static function remainingSql(string $until, string $seen): string
    {
        return "CASE WHEN $seen IS NULL THEN CASE WHEN $until<=? THEN $until-? ELSE 0 END WHEN $seen>? THEN $until-$seen ELSE $until-? END";
    }
    private function putLimit(string $key, float $until, float $now): void
    {
        $this->pdo->prepare('DELETE FROM discord_rest_limits WHERE substr(limit_key,1,?)<>?')->execute([strlen(self::KEY_PREFIX), self::KEY_PREFIX]);
        // Token rows (one per interaction) are dropped once they no longer apply, with their seen rows, so the table stays small.
        $tokenPrefix = self::KEY_PREFIX . self::TOKEN_ROUTE_PREFIX;
        $this->pdo->prepare('DELETE FROM discord_rest_limits WHERE substr(limit_key,1,?)=? AND until_at<=? AND COALESCE((SELECT s.until_at FROM discord_rest_limits s WHERE s.limit_key=? || substr(discord_rest_limits.limit_key,?)),0)<=?')->execute([strlen($tokenPrefix), $tokenPrefix, $now, self::KEY_PREFIX . self::SEEN_PREFIX, strlen(self::KEY_PREFIX) + 1, $now]);
        $seenTokenPrefix = self::KEY_PREFIX . self::SEEN_PREFIX . self::TOKEN_ROUTE_PREFIX;
        $this->pdo->prepare('DELETE FROM discord_rest_limits WHERE substr(limit_key,1,?)=? AND NOT EXISTS (SELECT 1 FROM discord_rest_limits l WHERE l.limit_key=? || substr(discord_rest_limits.limit_key,?))')->execute([strlen($seenTokenPrefix), $seenTokenPrefix, self::KEY_PREFIX, strlen(self::KEY_PREFIX . self::SEEN_PREFIX) + 1]);
        // The existing row keeps only what it still applies (re-based after a clock step); the longer wait wins.
        $seenKey = self::KEY_PREFIX . self::SEEN_PREFIX . $key;
        $query = $this->pdo->prepare('INSERT INTO discord_rest_limits(limit_key,until_at) VALUES (?,?) ON CONFLICT(limit_key) DO UPDATE SET until_at=MAX(excluded.until_at,?+MAX(0,' . self::remainingSql('discord_rest_limits.until_at', '(SELECT s.until_at FROM discord_rest_limits s WHERE s.limit_key=?)') . '))');
        $query->execute([self::KEY_PREFIX . $key, $until, $now, $seenKey, $now + self::LEGACY_HORIZON, $now, $seenKey, $now, $seenKey, $now]);
        $this->pdo->prepare('INSERT INTO discord_rest_limits(limit_key,until_at) VALUES (?,?) ON CONFLICT(limit_key) DO UPDATE SET until_at=excluded.until_at')->execute([$seenKey, $now]);
    }
    /**
     * Cooldown plus, while a limit row for this request still exists after it has run out, one probe at a time:
     * workers check and reserve under BEGIN IMMEDIATE, so concurrent workers never all resend at the moment a limit
     * ends. The others wait for the probe's answer (its 429 sets the next limit, its 2xx clears the spent rows).
     * Returns [seconds to wait, probe row keys to hand back to release()].
     */
    public function reserve(string $route, float $now, string $major = ''): array
    {
        // Ordinary requests (no limit row at all) take no write lock.
        $wait = $this->cooldown($route, $now, $major);
        if ($wait > 0 || $this->limitKeys($route, $major) === []) { return [$wait, []]; }
        $own = !$this->pdo->inTransaction();
        if ($own) { $this->pdo->exec('BEGIN IMMEDIATE'); }
        try {
            $wait = $this->cooldown($route, $now, $major);
            $probes = [];
            if ($wait <= 0) {
                $probePrefix = self::KEY_PREFIX . self::PROBE_PREFIX;
                // A probe left by a crashed worker expires on its own; one beyond the hold window can only be a clock step.
                $this->pdo->prepare('DELETE FROM discord_rest_limits WHERE substr(limit_key,1,?)=? AND (until_at<=? OR until_at>?)')->execute([strlen($probePrefix), $probePrefix, $now, $now + self::PROBE_HOLD]);
                foreach ($this->limitKeys($route, $major) as $key) { $probes[] = $probePrefix . substr($key, strlen(self::KEY_PREFIX)); }
                if ($probes !== []) {
                    $marks = implode(',', array_fill(0, count($probes), '?'));
                    $busy = $this->pdo->prepare("SELECT MAX(until_at) FROM discord_rest_limits WHERE limit_key IN ($marks)");
                    $busy->execute($probes);
                    $held = (float) $busy->fetchColumn();
                    if ($held > $now) { $wait = $held - $now; $probes = []; }
                    else {
                        $put = $this->pdo->prepare('INSERT INTO discord_rest_limits(limit_key,until_at) VALUES (?,?) ON CONFLICT(limit_key) DO UPDATE SET until_at=excluded.until_at');
                        foreach ($probes as $probe) { $put->execute([$probe, $now + self::PROBE_HOLD]); }
                    }
                }
            }
            if ($own) { $this->pdo->exec('COMMIT'); }
            return [$wait, $probes];
        } catch (\Throwable $error) { if ($own) { $this->pdo->exec('ROLLBACK'); } throw $error; }
    }
    /**
     * Hands a probe back. After a 2xx the spent limit rows it probed are cleared, so later requests need no probe.
     * A limit renewed meanwhile (a 429 from a request sent before the probe) keeps its row and its seen row.
     */
    public function release(array $probes, bool $succeeded, float $now): void
    {
        if ($probes === []) { return; }
        $probePrefix = self::KEY_PREFIX . self::PROBE_PREFIX;
        $drop = $this->pdo->prepare('DELETE FROM discord_rest_limits WHERE limit_key=?');
        $spent = $this->pdo->prepare('DELETE FROM discord_rest_limits WHERE limit_key=? AND until_at<=?');
        $orphan = $this->pdo->prepare('DELETE FROM discord_rest_limits WHERE limit_key=? AND NOT EXISTS (SELECT 1 FROM discord_rest_limits l WHERE l.limit_key=?)');
        foreach ($probes as $probe) {
            $drop->execute([$probe]);
            if (!$succeeded) { continue; }
            $tail = substr($probe, strlen($probePrefix));
            $spent->execute([self::KEY_PREFIX . $tail, $now]);
            $orphan->execute([self::KEY_PREFIX . self::SEEN_PREFIX . $tail, self::KEY_PREFIX . $tail]);
        }
    }
    /** v2 limit rows (spent or not) that apply to this request: the same keys cooldown() reads. */
    private function limitKeys(string $route, string $major): array
    {
        $query = $this->pdo->prepare('SELECT limit_key FROM discord_rest_limits WHERE limit_key IN (?, ?, ? || COALESCE((SELECT bucket_key FROM discord_rest_routes WHERE route_key=?), ?)) ORDER BY limit_key');
        $query->execute([self::KEY_PREFIX . (in_array($route, self::INTERACTION_ROUTES, true) ? 'none' : 'global'), self::KEY_PREFIX . 'route:' . self::routeKey($route, $major), self::KEY_PREFIX, $route, 'none']);
        return $query->fetchAll(PDO::FETCH_COLUMN);
    }
    /** Atomic per-definition lease and a total cap of three HTTP attempts. */
    public function claimCommand(string $key, float $now): string
    {
        $this->pdo->exec('BEGIN IMMEDIATE');
        try {
            $insert = $this->pdo->prepare('INSERT OR IGNORE INTO discord_rest_commands(command_key) VALUES (?)'); $insert->execute([$key]);
            $read = $this->pdo->prepare('SELECT * FROM discord_rest_commands WHERE command_key=?'); $read->execute([$key]); $row = $read->fetch(PDO::FETCH_ASSOC);
            $result = $row['state'] === 'done' ? 'done' : ((int) $row['attempts'] >= 3 ? 'attempts_exhausted' : (((float) $row['lease_until'] > $now || (float) $row['retry_at'] > $now) ? 'pending' : 'claimed'));
            if ($result === 'claimed') { $update = $this->pdo->prepare('UPDATE discord_rest_commands SET attempts=attempts+1,lease_until=? WHERE command_key=?'); $update->execute([$now + 20, $key]); }
            $this->pdo->exec('COMMIT'); return $result;
        } catch (\Throwable $error) { $this->pdo->exec('ROLLBACK'); throw $error; }
    }
    public function finishCommand(string $key, bool $ok, float $retryAt, bool $terminal = false): void
    {
        $query = $this->pdo->prepare('INSERT INTO discord_rest_commands(command_key,state,retry_at,attempts) VALUES (?,?,?,?) ON CONFLICT(command_key) DO UPDATE SET state=excluded.state,lease_until=0,retry_at=excluded.retry_at,attempts=CASE WHEN ? THEN 3 ELSE discord_rest_commands.attempts END');
        $query->execute([$key, $ok ? 'done' : 'pending', $retryAt, $terminal ? 3 : 0, $terminal ? 1 : 0]);
    }
}

