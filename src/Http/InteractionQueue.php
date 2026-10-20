<?php
declare(strict_types=1);
namespace RadioStamp\Http;

use PDO;
use RadioStamp\Security\TokenCipher;

final class InteractionQueue
{
    private ?string $instanceId=null;
    public function __construct(private readonly PDO $pdo, private readonly TokenCipher $cipher)
    {
        $pdo->exec('CREATE TABLE IF NOT EXISTS interaction_jobs (
            interaction_id TEXT PRIMARY KEY,
            action TEXT NOT NULL,
            target TEXT NOT NULL,
            user_id TEXT NOT NULL,
            display_name TEXT NOT NULL,
            request_hash TEXT NOT NULL,
            scope_mode TEXT NOT NULL DEFAULT \'mock\',
            token_ciphertext TEXT NOT NULL,
            created_at INTEGER NOT NULL,
            state TEXT NOT NULL DEFAULT \'pending\',
            attempts INTEGER NOT NULL DEFAULT 0,
            lease_id TEXT,
            claimed_at INTEGER,
            available_at INTEGER NOT NULL DEFAULT 0,
            result_code TEXT
        )');
        $columns = $pdo->query('PRAGMA table_info(interaction_jobs)')->fetchAll(PDO::FETCH_ASSOC);
        if (!in_array('scope_mode', array_column($columns, 'name'), true)) {
            $pdo->exec("ALTER TABLE interaction_jobs ADD COLUMN scope_mode TEXT NOT NULL DEFAULT 'mock'");
        }
    }

    /** Operations-only schema is prepared before ACK, without changing the generic validation queue. */
    public function initializeAcknowledgements():void
    {
        if($this->instanceId!==null){return;}
        $this->pdo->exec('CREATE TABLE IF NOT EXISTS operations_ack_receipts(interaction_id TEXT PRIMARY KEY,finished_at INTEGER NOT NULL)');
        $this->pdo->exec('CREATE TABLE IF NOT EXISTS operations_queue_instance(name TEXT PRIMARY KEY,instance_id TEXT NOT NULL)');
        $instance=$this->pdo->query("SELECT instance_id FROM operations_queue_instance WHERE name='operations'")->fetchColumn();
        if($instance===false){
            $this->pdo->prepare('INSERT OR IGNORE INTO operations_queue_instance(name,instance_id) VALUES(?,?)')->execute(['operations',bin2hex(random_bytes(32))]);
            $instance=$this->pdo->query("SELECT instance_id FROM operations_queue_instance WHERE name='operations'")->fetchColumn();
        }
        if(!is_string($instance)||preg_match('/\A[0-9a-f]{64}\z/D',$instance)!==1){throw new \RuntimeException('ack_handoff_unavailable');}
        $this->instanceId=$instance;
    }

    public function acknowledgementMetadata(string $id):?array
    {
        $this->initializeAcknowledgements();
        $q=$this->pdo->prepare('SELECT interaction_id,action,target,user_id,display_name,request_hash,scope_mode,token_ciphertext,created_at,state FROM interaction_jobs WHERE interaction_id=?');$q->execute([$id]);
        $row=$q->fetch(PDO::FETCH_ASSOC);if($row===false){return null;}
        $immutable=[];foreach(['interaction_id','action','target','user_id','display_name','request_hash','scope_mode','created_at']as$key){$immutable[$key]=$key==='created_at'?(int)$row[$key]:$row[$key];}
        $immutable['token_digest']=hash('sha256',$row['token_ciphertext']);
        return ['instance_id'=>$this->instanceId,'interaction_id'=>$row['interaction_id'],'request_hash'=>$row['request_hash'],
            'scope_mode'=>$row['scope_mode'],'created_at'=>(int)$row['created_at'],'expires_at'=>(int)$row['created_at']+900,
            'job_hash'=>hash('sha256',json_encode($immutable,JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR)),'state'=>$row['state']];
    }

    /** Caller authenticates the private journal first; this transaction binds it to the current queue row. */
    public function confirmAcknowledgement(array $proof,int $now):bool
    {
        $this->initializeAcknowledgements();$this->pdo->exec('BEGIN IMMEDIATE');
        try{
            $current=$this->acknowledgementMetadata($proof['interaction_id']);$matches=$current!==null;
            foreach(['instance_id','interaction_id','request_hash','scope_mode','created_at','expires_at','job_hash']as$key){if(($current[$key]??null)!==($proof[$key]??null)){$matches=false;}}
            if(!$matches||$current['scope_mode']!=='operations'||$current['expires_at']<=$now||!in_array($current['state'],['pending','processing'],true)){$this->pdo->exec('COMMIT');return false;}
            $this->pdo->prepare('INSERT OR IGNORE INTO operations_ack_receipts(interaction_id,finished_at) VALUES(?,?)')->execute([$proof['interaction_id'],$proof['finished_at']]);
            $this->pdo->exec('COMMIT');return true;
        }catch(\Throwable $error){$this->pdo->exec('ROLLBACK');throw $error;}
    }

    public function acknowledged(string $id):bool
    {
        $q=$this->pdo->prepare("SELECT 1 FROM operations_ack_receipts a JOIN interaction_jobs j ON j.interaction_id=a.interaction_id WHERE j.interaction_id=? AND j.scope_mode='operations' AND j.state IN ('pending','processing')");$q->execute([$id]);return $q->fetchColumn()!==false;
    }

    /** Independent maintenance: even idle, unacknowledged, or identity-failing operations must lose expired tokens. */
    public function expire(int $now):int
    {
        $expire=$this->pdo->prepare("UPDATE interaction_jobs SET state='expired',token_ciphertext='',lease_id=NULL,result_code='interaction_expired' WHERE state IN ('pending','processing') AND created_at<=?");
        $expire->execute([$now-900]);return $expire->rowCount();
    }

    /** One atomic insert provides deduplication across concurrent HTTP processes. */
    public function enqueue(array $job, string $token, int $now): bool
    {
        $statement = $this->pdo->prepare('INSERT INTO interaction_jobs
            (interaction_id, action, target, user_id, display_name, request_hash, scope_mode, token_ciphertext, created_at)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?) ON CONFLICT(interaction_id) DO NOTHING');
        $statement->execute([$job['interaction_id'], $job['action'], $job['target'], $job['user_id'], $job['display_name'], $job['request_hash'], $job['scope_mode'] ?? 'mock', $this->cipher->encrypt($job['interaction_id'], $token), $now]);
        if ($statement->rowCount() === 1) { return true; }
        $existing = $this->pdo->prepare('SELECT request_hash FROM interaction_jobs WHERE interaction_id=?');
        $existing->execute([$job['interaction_id']]);
        $hash = $existing->fetchColumn();
        if (!is_string($hash) || !hash_equals($hash, $job['request_hash'])) { throw new InteractionConflict('Interaction ID conflicts with prior request.'); }
        return false;
    }

    /** Expired leases retry the same interaction ID, preserving core idempotency. */
    public function claim(int $now, int $leaseSeconds = 120, ?string $interactionId = null, ?string $scopeMode = null): ?array
    {
        $this->pdo->exec('BEGIN IMMEDIATE');
        try {
            $this->expire($now);
            $reset = $this->pdo->prepare("UPDATE interaction_jobs SET state=CASE WHEN attempts >= 5 THEN 'failed' ELSE 'pending' END, token_ciphertext=CASE WHEN attempts >= 5 THEN '' ELSE token_ciphertext END, lease_id=NULL WHERE state='processing' AND claimed_at <= ?");
            $reset->execute([$now - $leaseSeconds]);
            $where = "state='pending' AND attempts < 5 AND available_at <= ?";
            $parameters = [$now];
            if ($interactionId !== null) { $where .= ' AND interaction_id=?'; $parameters[] = $interactionId; }
            if ($scopeMode !== null) { $where .= ' AND scope_mode=?'; $parameters[] = $scopeMode; }
            $select = $this->pdo->prepare('SELECT * FROM interaction_jobs WHERE ' . $where . ' ORDER BY created_at, interaction_id LIMIT 1');
            $select->execute($parameters);
            $job = $select->fetch(PDO::FETCH_ASSOC);
            if ($job === false) { $this->pdo->exec('COMMIT'); return null; }
            $lease = bin2hex(random_bytes(16));
            $claim = $this->pdo->prepare("UPDATE interaction_jobs SET state='processing', lease_id=?, claimed_at=?, attempts=attempts+1 WHERE interaction_id=? AND state='pending'");
            $claim->execute([$lease, $now, $job['interaction_id']]);
            $this->pdo->exec('COMMIT');
            $job['lease_id'] = $lease;
            $job['attempts'] = (int) $job['attempts'] + 1;
            return $job;
        } catch (\Throwable $error) {
            $this->pdo->exec('ROLLBACK');
            throw $error;
        }
    }

    public function token(array $job): string
    {
        return $this->cipher->decrypt($job['interaction_id'], $job['token_ciphertext']);
    }

    /** A final local-error response still requires the live, unexpired worker lease. */
    public function owns(array $job, int $now): bool
    {
        $query = $this->pdo->prepare("SELECT 1 FROM interaction_jobs WHERE interaction_id=? AND state='processing' AND lease_id=? AND attempts=? AND created_at>? AND claimed_at>?");
        $query->execute([$job['interaction_id'], $job['lease_id'], (int)$job['attempts'], $now - 900, $now - 120]);
        return $query->fetchColumn() !== false;
    }

    public function finish(array $job, string $code): void
    {
        $statement = $this->pdo->prepare("UPDATE interaction_jobs SET state='done', result_code=?, token_ciphertext='', lease_id=NULL WHERE interaction_id=? AND state='processing' AND lease_id=?");
        $statement->execute([$code, $job['interaction_id'], $job['lease_id']]);
    }

    /** Return the owned transition, or null after another worker/expiry took ownership. */
    public function retry(array $job, string $code, int $now): ?string
    {
        $statement = $this->pdo->prepare("UPDATE interaction_jobs SET state=CASE WHEN attempts >= 5 THEN 'failed' ELSE 'pending' END, token_ciphertext=CASE WHEN attempts >= 5 THEN '' ELSE token_ciphertext END, result_code=?, available_at=?, lease_id=NULL WHERE interaction_id=? AND state='processing' AND lease_id=? AND attempts=?");
        $statement->execute([$code, $now + min(60, 2 ** (int) $job['attempts']), $job['interaction_id'], $job['lease_id'], (int)$job['attempts']]);
        return $statement->rowCount()===1?((int)$job['attempts']>=5?'failed':'pending'):null;
    }
}
