<?php
declare(strict_types=1);
namespace RadioStamp\Discord;

use PDO;

/** Use the private application SQLite connection; this stores no credentials or payloads. */
final class RestStateStore
{
    public function __construct(private readonly PDO $pdo)
    {
        $pdo->exec('CREATE TABLE IF NOT EXISTS discord_rest_limits (limit_key TEXT PRIMARY KEY, until_at REAL NOT NULL)');
        $pdo->exec('CREATE TABLE IF NOT EXISTS discord_rest_routes (route_key TEXT PRIMARY KEY, bucket_key TEXT NOT NULL)');
        $pdo->exec("CREATE TABLE IF NOT EXISTS discord_rest_commands (command_key TEXT PRIMARY KEY, attempts INTEGER NOT NULL DEFAULT 0, state TEXT NOT NULL DEFAULT 'pending', lease_until REAL NOT NULL DEFAULT 0, retry_at REAL NOT NULL DEFAULT 0)");
    }
    public function cooldown(string $route, float $now): float
    {
        $query = $this->pdo->prepare('SELECT MAX(until_at) FROM discord_rest_limits WHERE limit_key IN (?, ?, COALESCE((SELECT bucket_key FROM discord_rest_routes WHERE route_key=?), ?))');
        $query->execute(['global', 'route:' . $route, $route, 'none']);
        return max(0.0, (float) $query->fetchColumn() - $now);
    }
    public function observe(string $route, string $major, array $headers, int $status, float $retryAfter, bool $global, float $now): void
    {
        $bucket = 'route:' . $route;
        if (isset($headers['x-ratelimit-bucket']) && is_string($headers['x-ratelimit-bucket']) && preg_match('/\A[A-Za-z0-9._-]{1,128}\z/D', $headers['x-ratelimit-bucket'])) {
            $bucket = 'bucket:' . hash('sha256', $major . ':' . $headers['x-ratelimit-bucket']);
            $map = $this->pdo->prepare('INSERT INTO discord_rest_routes(route_key,bucket_key) VALUES (?,?) ON CONFLICT(route_key) DO UPDATE SET bucket_key=excluded.bucket_key');
            $map->execute([$route, $bucket]);
        }
        $reset = is_numeric($headers['x-ratelimit-reset-after'] ?? null) && is_finite((float) $headers['x-ratelimit-reset-after']) && (float) $headers['x-ratelimit-reset-after'] >= 0 ? (float) $headers['x-ratelimit-reset-after'] : 86400.0;
        if (($headers['x-ratelimit-remaining'] ?? null) === '0' && $reset > 0) { $this->putLimit($bucket, $this->until($now, $reset)); }
        if ($status === 429) {
            $this->putLimit($global ? 'global' : $bucket, $this->until($now, max(0.25, $retryAfter)));
            // Route fallback protects requests before a bucket has been learned.
            $this->putLimit('route:' . $route, $this->until($now, max(0.25, $retryAfter)));
        }
    }
    private function until(float $now, float $duration): float { $until = $now + $duration; return is_finite($until) ? $until : PHP_FLOAT_MAX; }
    private function putLimit(string $key, float $until): void
    {
        $query = $this->pdo->prepare('INSERT INTO discord_rest_limits(limit_key,until_at) VALUES (?,?) ON CONFLICT(limit_key) DO UPDATE SET until_at=MAX(until_at,excluded.until_at)');
        $query->execute([$key, $until]);
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
