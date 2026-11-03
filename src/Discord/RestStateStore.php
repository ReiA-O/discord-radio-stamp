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
     * A valid 429 retry interval is honoured in full up to a day. Discord documents no maximum, so a longer value is
     * retried after a day: that costs one request, whose 429 sets the next day. Without a finite bound one bad value
     * would stop all REST indefinitely, and rows beyond now+MAX_RETRY_AFTER could not be told apart from a clock step.
     */
    public const MAX_RETRY_AFTER = 86400.0;
    /** Rows written by this release; unprefixed rows from older releases are never read and are purged on write. */
    private const KEY_PREFIX = 'v2:';
    /** Interaction webhook endpoints are not bound by the bot's global rate limit. */
    private const INTERACTION_ROUTES = ['interaction_edit'];
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
    public function cooldown(string $route, float $now): float
    {
        // Older releases' rows have no prefix, so a day-long legacy row can never come back into range as time passes.
        // Rows beyond now+MAX_RETRY_AFTER can only come from a clock step and are ignored.
        $query = $this->pdo->prepare('SELECT MAX(until_at) FROM discord_rest_limits WHERE until_at<=? AND limit_key IN (?, ?, ? || COALESCE((SELECT bucket_key FROM discord_rest_routes WHERE route_key=?), ?))');
        $query->execute([$now + self::MAX_RETRY_AFTER, self::KEY_PREFIX . (in_array($route, self::INTERACTION_ROUTES, true) ? 'none' : 'global'), self::KEY_PREFIX . 'route:' . $route, self::KEY_PREFIX, $route, 'none']);
        return min(self::MAX_RETRY_AFTER, max(0.0, (float) $query->fetchColumn() - $now));
    }
    public function observe(string $route, string $major, array $headers, int $status, float $retryAfter, bool $global, float $now): void
    {
        $bucket = 'route:' . $route;
        if (isset($headers['x-ratelimit-bucket']) && is_string($headers['x-ratelimit-bucket']) && preg_match('/\A[A-Za-z0-9._-]{1,128}\z/D', $headers['x-ratelimit-bucket'])) {
            $bucket = 'bucket:' . hash('sha256', $major . ':' . $headers['x-ratelimit-bucket']);
            $map = $this->pdo->prepare('INSERT INTO discord_rest_routes(route_key,bucket_key) VALUES (?,?) ON CONFLICT(route_key) DO UPDATE SET bucket_key=excluded.bucket_key');
            $map->execute([$route, $bucket]);
        }
        $reset = is_numeric($headers['x-ratelimit-reset-after'] ?? null) && is_finite((float) $headers['x-ratelimit-reset-after']) && (float) $headers['x-ratelimit-reset-after'] >= 0 ? (float) $headers['x-ratelimit-reset-after'] : 5.0;
        if (($headers['x-ratelimit-remaining'] ?? null) === '0' && $reset > 0) { $this->putLimit($bucket, $this->until($now, $reset), $now); }
        if ($status === 429) {
            // An interaction route never writes the bot-wide global limit it is exempt from.
            $global = $global && !in_array($route, self::INTERACTION_ROUTES, true);
            // A valid Retry-After is honoured up to a day; only non-finite input falls back to the one-hour cap.
            $retryUntil = $this->until($now, max(0.25, $retryAfter), self::MAX_RETRY_AFTER);
            $this->putLimit($global ? 'global' : $bucket, $retryUntil, $now);
            // Route fallback protects requests before a bucket has been learned.
            $this->putLimit('route:' . $route, $retryUntil, $now);
        }
    }
    private function until(float $now, float $duration, float $cap = self::MAX_COOLDOWN): float { return $now + (is_finite($duration) ? max(0.0, min($cap, $duration)) : self::MAX_COOLDOWN); }
    private function putLimit(string $key, float $until, float $now): void
    {
        $this->pdo->prepare('DELETE FROM discord_rest_limits WHERE substr(limit_key,1,?)<>?')->execute([strlen(self::KEY_PREFIX), self::KEY_PREFIX]);
        // A row beyond the day cap (clock step) is replaced instead of being kept by MAX().
        $query = $this->pdo->prepare('INSERT INTO discord_rest_limits(limit_key,until_at) VALUES (?,?) ON CONFLICT(limit_key) DO UPDATE SET until_at=CASE WHEN discord_rest_limits.until_at>? THEN excluded.until_at ELSE MAX(discord_rest_limits.until_at,excluded.until_at) END');
        $query->execute([self::KEY_PREFIX . $key, $until, $now + self::MAX_RETRY_AFTER]);
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

