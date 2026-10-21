<?php
declare(strict_types=1);
namespace RadioStamp\Core;

use PDO;

final class Database
{
    private PDO $connection;
    private bool $writing = false;

    public function __construct(string $path, int $busyTimeoutMs = 5000,bool $initialize=true)
    {
        if ($path === '') { throw new \InvalidArgumentException('An application database is required.'); }
        if ($busyTimeoutMs < 0 || $busyTimeoutMs > 5000) { throw new \InvalidArgumentException('Busy timeout must be between zero and five seconds.'); }
        if ($path !== ':memory:') {
            $directory = dirname($path);
            if (!is_dir($directory) && !mkdir($directory, 0700, true) && !is_dir($directory)) {
                throw new \RuntimeException('Cannot prepare the private application database.');
            }
        }
        $this->connection = new PDO('sqlite:' . $path, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
        $this->connection->exec('PRAGMA busy_timeout = ' . $busyTimeoutMs);
        $this->connection->exec('PRAGMA foreign_keys = ON');
        if(!$initialize){return;}
        $this->connection->exec('PRAGMA journal_mode = WAL');
        $this->connection->exec('PRAGMA synchronous = NORMAL');
        $this->connection->exec(<<<'SQL'
CREATE TABLE IF NOT EXISTS sessions (
 id TEXT PRIMARY KEY, scheduled_at INTEGER NOT NULL UNIQUE, count_date TEXT NOT NULL,
 opens_at INTEGER NOT NULL, unstarted_closes_at INTEGER NOT NULL,
 status TEXT NOT NULL CHECK(status IN ('planned','open','started','closing','closed')),
 started_at INTEGER, started_by TEXT, closes_at INTEGER, warning_sent_at INTEGER, closed_at INTEGER,
 slot_id TEXT, stamp TEXT
);
CREATE TABLE IF NOT EXISTS participants (
 user_id TEXT PRIMARY KEY, total_count INTEGER NOT NULL DEFAULT 0 CHECK(total_count >= 0),
 latest_threshold INTEGER, latest_title TEXT
);
CREATE TABLE IF NOT EXISTS attendance (
 session_id TEXT NOT NULL REFERENCES sessions(id), user_id TEXT NOT NULL REFERENCES participants(user_id),
 count_date TEXT NOT NULL, attended_at INTEGER NOT NULL, ordinal INTEGER NOT NULL, stamp TEXT NOT NULL,
 PRIMARY KEY(session_id,user_id), UNIQUE(user_id,ordinal)
);
CREATE INDEX IF NOT EXISTS attendance_month ON attendance(user_id,count_date);
CREATE INDEX IF NOT EXISTS attendance_jst_days ON attendance(user_id,attended_at);
CREATE TABLE IF NOT EXISTS memorial_awards (
 user_id TEXT NOT NULL REFERENCES participants(user_id), milestone INTEGER NOT NULL,
 reached_at INTEGER NOT NULL, PRIMARY KEY(user_id,milestone)
);
CREATE TABLE IF NOT EXISTS core_interactions (
 id TEXT PRIMARY KEY, operation TEXT NOT NULL, session_id TEXT NOT NULL, user_id TEXT NOT NULL,
 result_json TEXT NOT NULL, created_at INTEGER NOT NULL
);
CREATE TABLE IF NOT EXISTS achievement_notices (
 user_id TEXT NOT NULL REFERENCES participants(user_id), threshold INTEGER NOT NULL,
 total_count INTEGER NOT NULL, status TEXT NOT NULL CHECK(status IN ('pending','sending','sent','uncertain')),
 created_at INTEGER NOT NULL, attempted_at INTEGER, sent_at INTEGER, claim_owner TEXT,
 PRIMARY KEY(user_id,threshold)
);
CREATE TABLE IF NOT EXISTS day_achievement_notices (
 user_id TEXT NOT NULL REFERENCES participants(user_id), milestone INTEGER NOT NULL,
 total_days INTEGER NOT NULL, owner_user_id TEXT,
 status TEXT NOT NULL CHECK(status IN ('pending','sending','sent','uncertain','failed')),
 created_at INTEGER NOT NULL, attempted_at INTEGER, sent_at INTEGER, claim_owner TEXT,
 attempts INTEGER NOT NULL DEFAULT 0, PRIMARY KEY(user_id,milestone)
);
CREATE TABLE IF NOT EXISTS receipt_announcements (
 session_id TEXT PRIMARY KEY REFERENCES sessions(id),
 status TEXT NOT NULL CHECK(status IN ('pending','sending','sent','uncertain')),
 created_at INTEGER NOT NULL, attempted_at INTEGER, sent_at INTEGER, claim_owner TEXT, expired_at INTEGER
);
CREATE TABLE IF NOT EXISTS core_effects (
 effect_key TEXT PRIMARY KEY, kind TEXT NOT NULL, payload_json TEXT NOT NULL,
 status TEXT NOT NULL CHECK(status IN ('pending','done')), attempts INTEGER NOT NULL DEFAULT 0,
 last_attempt_at INTEGER, completed_at INTEGER, lease_until INTEGER NOT NULL DEFAULT 0, lease_owner TEXT,
 expires_at INTEGER, cancelled_at INTEGER
);
CREATE TABLE IF NOT EXISTS weekly_ranking_weeks (
 week_key TEXT PRIMARY KEY, guild_id TEXT NOT NULL, week_start INTEGER NOT NULL, week_end INTEGER NOT NULL,
 eligible_at INTEGER NOT NULL, expires_at INTEGER NOT NULL, channel_id TEXT NOT NULL,
 snapshot_json TEXT NOT NULL, part_count INTEGER NOT NULL,
 status TEXT NOT NULL CHECK(status IN ('pending','sent','hold','failed','skipped','expired')),
 created_at INTEGER NOT NULL, UNIQUE(guild_id,week_start)
);
CREATE TABLE IF NOT EXISTS weekly_ranking_parts (
 part_key TEXT PRIMARY KEY, week_key TEXT NOT NULL REFERENCES weekly_ranking_weeks(week_key), part_no INTEGER NOT NULL,
 payload_json TEXT NOT NULL, status TEXT NOT NULL CHECK(status IN ('pending','sending','sent','hold','failed','expired')),
 attempts INTEGER NOT NULL DEFAULT 0, available_at INTEGER NOT NULL DEFAULT 0, claim_owner TEXT,
 attempted_at INTEGER, sent_at INTEGER, message_id TEXT, outcome TEXT, UNIQUE(week_key,part_no)
);
SQL);
        $columns = $this->connection->query('PRAGMA table_info(core_effects)')->fetchAll(PDO::FETCH_COLUMN, 1);
        foreach (['lease_until' => 'INTEGER NOT NULL DEFAULT 0', 'lease_owner' => 'TEXT', 'expires_at' => 'INTEGER', 'cancelled_at' => 'INTEGER'] as $column => $type) {
            if (!in_array($column, $columns, true)) {
                try { $this->connection->exec('ALTER TABLE core_effects ADD COLUMN ' . $column . ' ' . $type); }
                catch (\PDOException $error) {
                    if (!in_array($column, $this->connection->query('PRAGMA table_info(core_effects)')->fetchAll(PDO::FETCH_COLUMN, 1), true)) { throw $error; }
                }
            }
        }
        foreach(['sessions'=>['slot_id'=>'TEXT','stamp'=>'TEXT'],'receipt_announcements'=>['claim_owner'=>'TEXT','expired_at'=>'INTEGER'],'achievement_notices'=>['claim_owner'=>'TEXT','attempts'=>'INTEGER NOT NULL DEFAULT 0','retry_stopped_at'=>'INTEGER']]as$table=>$additions){
            $existing=$this->connection->query('PRAGMA table_info('.$table.')')->fetchAll(PDO::FETCH_COLUMN,1);
            foreach($additions as$column=>$type){
                if(in_array($column,$existing,true)){continue;}
                try{$this->connection->exec('ALTER TABLE '.$table.' ADD COLUMN '.$column.' '.$type);}
                catch(\PDOException $error){if(!in_array($column,$this->connection->query('PRAGMA table_info('.$table.')')->fetchAll(PDO::FETCH_COLUMN,1),true)){throw $error;}}
            }
        }
        // Legacy NULL bindings stay outside the index; existing values are never inferred or rewritten.
        $this->connection->exec('CREATE UNIQUE INDEX IF NOT EXISTS sessions_logical_slot_day ON sessions(count_date,slot_id) WHERE slot_id IS NOT NULL');
        if ($path !== ':memory:') { @chmod($path, 0600); }
    }

    public function pdo(): PDO { return $this->connection; }

    public function inTransaction(): bool { return $this->writing || $this->connection->inTransaction(); }

    /** Serialize read/validate/write decisions across processes, with a bounded busy wait. */
    public function transaction(callable $operation): mixed
    {
        if ($this->writing) { throw new \LogicException('Nested core transactions are not supported.'); }
        $this->connection->exec('BEGIN IMMEDIATE');
        $this->writing = true;
        try {
            $result = $operation($this->connection);
            $this->connection->exec('COMMIT');
            return $result;
        } catch (\Throwable $error) {
            $this->connection->exec('ROLLBACK');
            throw $error;
        } finally { $this->writing = false; }
    }
}
