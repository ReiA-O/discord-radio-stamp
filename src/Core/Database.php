<?php
declare(strict_types=1);
namespace RadioStamp\Core;

use PDO;

final class Database
{
    private PDO $connection;
    private bool $writing = false;

    public static function openExisting(string $path,int $busyTimeoutMs=5000):self
    {
        $database=new self($path,$busyTimeoutMs,false,true);$database->requireCoreSchema();return $database;
    }

    public function __construct(string $path, int $busyTimeoutMs = 5000,bool $initialize=true,bool $existingOnly=false)
    {
        if ($path === '') { throw new \InvalidArgumentException('An application database is required.'); }
        if ($busyTimeoutMs < 0 || $busyTimeoutMs > 5000) { throw new \InvalidArgumentException('Busy timeout must be between zero and five seconds.'); }
        if($existingOnly&&($path===':memory:'||is_link($path)||!is_file($path))){throw new \RuntimeException('The existing application database is unavailable.');}
        if ($path !== ':memory:') {
            $directory = dirname($path);
            if (!$existingOnly&&!is_dir($directory) && !mkdir($directory, 0700, true) && !is_dir($directory)) {
                throw new \RuntimeException('Cannot prepare the private application database.');
            }
        }
        $options=[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC];
        if($existingOnly){$options[PDO::SQLITE_ATTR_OPEN_FLAGS]=PDO::SQLITE_OPEN_READWRITE;}
        $this->connection = new PDO('sqlite:' . $path, null, null, $options);
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

    /** Structural validation only: no CREATE, ALTER, journal change, or seed write. */
    public static function requireTable(PDO $pdo,string $table,array $expected):void
    {
        if(preg_match('/\A[a-z_][a-z0-9_]*\z/D',$table)!==1||$expected===[]){throw new \RuntimeException('private_schema_unavailable');}
        $type=$pdo->prepare('SELECT type FROM sqlite_master WHERE name=?');$type->execute([$table]);
        if($type->fetchColumn()!=='table'){throw new \RuntimeException('private_schema_unavailable');}
        $rows=$pdo->query('PRAGMA table_info('.$table.')')->fetchAll(PDO::FETCH_ASSOC);$columns=[];$primary=[];
        foreach($rows as$row){$columns[$row['name']]=$row;if((int)$row['pk']>0){$primary[(int)$row['pk']]=$row['name'];}}
        ksort($primary);$primary=array_values($primary);
        foreach($expected as$name=>$contract){
            $row=$columns[$name]??null;
            if(!is_array($row)||strtoupper((string)$row['type'])!==$contract[0]||(int)$row['notnull']!==$contract[1]
                ||self::schemaDefault($row['dflt_value'])!==$contract[2]||(int)$row['pk']!==$contract[3]){throw new \RuntimeException('private_schema_unavailable');}
        }
        $requiredPrimary=[];foreach($expected as$name=>$contract){if($contract[3]>0){$requiredPrimary[$contract[3]]=$name;}}ksort($requiredPrimary);
        if($primary!==array_values($requiredPrimary)){throw new \RuntimeException('private_schema_unavailable');}
    }

    private static function schemaDefault(mixed $value):?string
    {
        if($value===null){return null;}$value=trim((string)$value);
        while(strlen($value)>1&&$value[0]==='('&&$value[strlen($value)-1]===')'){$value=trim(substr($value,1,-1));}
        if(strlen($value)>1&&(($value[0]==="'"&&$value[strlen($value)-1]==="'")||($value[0]==='"'&&$value[strlen($value)-1]==='"'))){$value=substr($value,1,-1);}
        return $value;
    }

    private function requireCoreSchema():void
    {
        $n=static fn(string $type,int $notNull=0,?string $default=null,int $primary=0):array=>[$type,$notNull,$default,$primary];
        $contracts=[
            'sessions'=>['id'=>$n('TEXT',0,null,1),'scheduled_at'=>$n('INTEGER',1),'count_date'=>$n('TEXT',1),'opens_at'=>$n('INTEGER',1),'unstarted_closes_at'=>$n('INTEGER',1),'status'=>$n('TEXT',1),'started_at'=>$n('INTEGER'),'started_by'=>$n('TEXT'),'closes_at'=>$n('INTEGER'),'warning_sent_at'=>$n('INTEGER'),'closed_at'=>$n('INTEGER'),'slot_id'=>$n('TEXT'),'stamp'=>$n('TEXT')],
            'participants'=>['user_id'=>$n('TEXT',0,null,1),'total_count'=>$n('INTEGER',1,'0'),'latest_threshold'=>$n('INTEGER'),'latest_title'=>$n('TEXT')],
            'attendance'=>['session_id'=>$n('TEXT',1,null,1),'user_id'=>$n('TEXT',1,null,2),'count_date'=>$n('TEXT',1),'attended_at'=>$n('INTEGER',1),'ordinal'=>$n('INTEGER',1),'stamp'=>$n('TEXT',1)],
            'memorial_awards'=>['user_id'=>$n('TEXT',1,null,1),'milestone'=>$n('INTEGER',1,null,2),'reached_at'=>$n('INTEGER',1)],
            'core_interactions'=>['id'=>$n('TEXT',0,null,1),'operation'=>$n('TEXT',1),'session_id'=>$n('TEXT',1),'user_id'=>$n('TEXT',1),'result_json'=>$n('TEXT',1),'created_at'=>$n('INTEGER',1)],
            'achievement_notices'=>['user_id'=>$n('TEXT',1,null,1),'threshold'=>$n('INTEGER',1,null,2),'total_count'=>$n('INTEGER',1),'status'=>$n('TEXT',1),'created_at'=>$n('INTEGER',1),'attempted_at'=>$n('INTEGER'),'sent_at'=>$n('INTEGER'),'claim_owner'=>$n('TEXT'),'attempts'=>$n('INTEGER',1,'0'),'retry_stopped_at'=>$n('INTEGER')],
            'day_achievement_notices'=>['user_id'=>$n('TEXT',1,null,1),'milestone'=>$n('INTEGER',1,null,2),'total_days'=>$n('INTEGER',1),'owner_user_id'=>$n('TEXT'),'status'=>$n('TEXT',1),'created_at'=>$n('INTEGER',1),'attempted_at'=>$n('INTEGER'),'sent_at'=>$n('INTEGER'),'claim_owner'=>$n('TEXT'),'attempts'=>$n('INTEGER',1,'0')],
            'receipt_announcements'=>['session_id'=>$n('TEXT',0,null,1),'status'=>$n('TEXT',1),'created_at'=>$n('INTEGER',1),'attempted_at'=>$n('INTEGER'),'sent_at'=>$n('INTEGER'),'claim_owner'=>$n('TEXT'),'expired_at'=>$n('INTEGER')],
            'core_effects'=>['effect_key'=>$n('TEXT',0,null,1),'kind'=>$n('TEXT',1),'payload_json'=>$n('TEXT',1),'status'=>$n('TEXT',1),'attempts'=>$n('INTEGER',1,'0'),'last_attempt_at'=>$n('INTEGER'),'completed_at'=>$n('INTEGER'),'lease_until'=>$n('INTEGER',1,'0'),'lease_owner'=>$n('TEXT'),'expires_at'=>$n('INTEGER'),'cancelled_at'=>$n('INTEGER')],
            'weekly_ranking_weeks'=>['week_key'=>$n('TEXT',0,null,1),'guild_id'=>$n('TEXT',1),'week_start'=>$n('INTEGER',1),'week_end'=>$n('INTEGER',1),'eligible_at'=>$n('INTEGER',1),'expires_at'=>$n('INTEGER',1),'channel_id'=>$n('TEXT',1),'snapshot_json'=>$n('TEXT',1),'part_count'=>$n('INTEGER',1),'status'=>$n('TEXT',1),'created_at'=>$n('INTEGER',1)],
            'weekly_ranking_parts'=>['part_key'=>$n('TEXT',0,null,1),'week_key'=>$n('TEXT',1),'part_no'=>$n('INTEGER',1),'payload_json'=>$n('TEXT',1),'status'=>$n('TEXT',1),'attempts'=>$n('INTEGER',1,'0'),'available_at'=>$n('INTEGER',1,'0'),'claim_owner'=>$n('TEXT'),'attempted_at'=>$n('INTEGER'),'sent_at'=>$n('INTEGER'),'message_id'=>$n('TEXT'),'outcome'=>$n('TEXT')],
        ];
        foreach($contracts as$table=>$columns){self::requireTable($this->connection,$table,$columns);}
        foreach(['attendance_month','attendance_jst_days','sessions_logical_slot_day']as$index){$q=$this->connection->prepare("SELECT 1 FROM sqlite_master WHERE type='index' AND name=? AND sql IS NOT NULL");$q->execute([$index]);if($q->fetchColumn()===false){throw new \RuntimeException('private_schema_unavailable');}}
    }

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
