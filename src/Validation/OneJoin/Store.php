<?php
declare(strict_types=1);
namespace RadioStamp\Validation\OneJoin;

use PDO;
use RadioStamp\Core\Clock;
use RadioStamp\Core\Database;
use RadioStamp\Discord\PresenceDiagnostic;
use RadioStamp\Http\InteractionController;
use RadioStamp\Http\InteractionQueue;
use RadioStamp\Http\PrivateConfig;
use RadioStamp\Http\Response;
use RadioStamp\Security\TokenCipher;

final class Store
{
    private const APP_ID = 1381191498; // Dedicated SQLite application_id, RSOJ.
    public readonly Database $db;
    public readonly string $root;

    public function __construct(string $privateBase, string $publicRoot, private readonly array $activation, Clock $clock, bool $create)
    {
        if (!Trial::validActivation($activation)) { throw new \RuntimeException('trial_window_invalid'); }
        $base = realpath($privateBase); $parent = $base === false ? '' : $base . DIRECTORY_SEPARATOR . 'instances';
        if ($base === false || is_link($privateBase) || !is_dir($parent) || is_link($parent)) { throw new \RuntimeException('trial_storage_unavailable'); }
        $this->root = $parent . DIRECTORY_SEPARATOR . Trial::NAME;
        PrivateConfig::requirePrivatePath($this->root, $publicRoot);
        if (is_link($this->root)) { throw new \RuntimeException('trial_storage_unavailable'); }
        $marker = $this->root . DIRECTORY_SEPARATOR . 'trial.marker';
        $file = $this->root . DIRECTORY_SEPARATOR . 'fixture.sqlite';
        $expected = Trial::NAME . "\n";
        $fresh = !file_exists($this->root);
        if ($fresh) {
            if (!$create || !Trial::active($activation, $clock)) { throw new \RuntimeException('trial_storage_unavailable'); }
            if (!mkdir($this->root, 0700)) { throw new \RuntimeException('trial_storage_unavailable'); }
            PrivateConfig::requirePrivatePath($file, $publicRoot);
            $handle = fopen($marker, 'x+b');
            if ($handle === false) { throw new \RuntimeException('trial_storage_unavailable'); }
            try { if (fwrite($handle, $expected) !== strlen($expected) || !fflush($handle)) { throw new \RuntimeException('trial_storage_unavailable'); } }
            finally { fclose($handle); }
            chmod($marker, 0600);
        }
        PrivateConfig::requirePrivatePath($file, $publicRoot);
        foreach (['trial.marker', 'fixture.sqlite', 'fixture.sqlite-wal', 'fixture.sqlite-shm', 'report.safe.json'] as $name) {
            $path = $this->root . DIRECTORY_SEPARATOR . $name;
            if (is_link($path) || (file_exists($path) && !is_file($path))) { throw new \RuntimeException('trial_storage_unavailable'); }
        }
        if (!is_file($marker) || filesize($marker) !== strlen($expected) || file_get_contents($marker) !== $expected) { throw new \RuntimeException('trial_storage_unavailable'); }
        if (!$fresh) {
            if (!is_file($file)) { throw new \RuntimeException('trial_storage_unavailable'); }
            $probe = new PDO('sqlite:' . $file, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
            if ((int)$probe->query('PRAGMA application_id')->fetchColumn() !== self::APP_ID) { throw new \RuntimeException('trial_storage_unavailable'); }
            unset($probe);
        }
        $this->db = new Database($file, 200);
        $pdo = $this->db->pdo();
        if ($fresh) {
            $pdo->exec('PRAGMA application_id=' . self::APP_ID);
            $pdo->exec('CREATE TABLE trial_meta (id INTEGER PRIMARY KEY CHECK(id=1), data TEXT NOT NULL)');
            $pdo->exec('CREATE TABLE trial_operations (id TEXT PRIMARY KEY, step TEXT NOT NULL, status TEXT NOT NULL, code TEXT, ack INTEGER NOT NULL DEFAULT 0, followup INTEGER NOT NULL DEFAULT 0, image INTEGER NOT NULL DEFAULT 0, elapsed_bucket TEXT, requests INTEGER NOT NULL DEFAULT 0, outcome TEXT, presence_http_status INTEGER, presence_classification TEXT)');
            $jst = $clock->now()->setTimezone(new \DateTimeZone('Asia/Tokyo'));
            $state = ['run' => Trial::NAME, 'starts_at' => $activation['starts_at'], 'expires_at' => $activation['expires_at'],
                'overall_deadline_at' => Window::overallDeadline($activation), 'activation_hash' => hash('sha256', json_encode($activation, JSON_THROW_ON_ERROR)),
                'session' => $jst->format('Y-m-d') . '_0700', 'month' => $jst->format('Y-m'), 'phase' => 'check', 'pending' => null,
                'check_count' => 0, 'button_count' => 0, 'post_attempted' => false, 'message_id' => null, 'delete_attempted' => false,
                'cleanup' => 'not_needed', 'seeded' => false, 'api_suspended' => false, 'bot_suspended' => false];
            $pdo->prepare('INSERT INTO trial_meta VALUES(1,?)')->execute([json_encode($state, JSON_THROW_ON_ERROR)]);
        }
        $state = $this->state();
        if ($state['run'] !== Trial::NAME || $state['starts_at'] !== $activation['starts_at'] || $state['expires_at'] !== $activation['expires_at'] ||
            $state['activation_hash'] !== hash('sha256', json_encode($activation, JSON_THROW_ON_ERROR))) { throw new \RuntimeException('trial_storage_unavailable'); }
    }

    public static function exists(string $privateBase): bool { return file_exists(rtrim($privateBase, '/\\') . '/instances/' . Trial::NAME); }
    public function state(): array { return json_decode((string)$this->db->pdo()->query('SELECT data FROM trial_meta WHERE id=1')->fetchColumn(), true, 16, JSON_THROW_ON_ERROR); }
    public function update(\Closure $change): mixed
    {
        return $this->db->transaction(function (PDO $pdo) use ($change): mixed {
            $state = $this->state(); $result = $change($state);
            $pdo->prepare('UPDATE trial_meta SET data=? WHERE id=1')->execute([json_encode($state, JSON_THROW_ON_ERROR)]);
            return $result;
        });
    }
    public function operation(string $id): ?array
    {
        $query = $this->db->pdo()->prepare('SELECT * FROM trial_operations WHERE id=?'); $query->execute([$id]);
        $row = $query->fetch(); return $row === false ? null : $row;
    }

    public function admit(array $config, Clock $clock, array $server, string $raw, array $data, string $kind): Response
    {
        $queue = new InteractionQueue($this->db->pdo(), new TokenCipher($config['token_encryption_key_hex']));
        return $this->db->transaction(function (PDO $pdo) use ($config, $clock, $server, $raw, $data, $kind, $queue): Response {
            $state = $this->state();
            if ($this->operation($data['id']) !== null) { return Trial::privateReply('このテスト要求は受付済みです。再処理しません。'); }
            if ($state['pending'] !== null) { return Trial::privateReply('直前の処理が完了するまで待ってください。'); }
            $cleanup = $kind === 'check' && $state['check_count'] === 1;
            if ($kind === 'check') {
                if ($state['check_count'] >= 2 || (!$cleanup && $state['phase'] !== 'check')) { return Trial::privateReply('テストの追加実行は停止しています。'); }
            } elseif (!Trial::active($this->activation, $clock) ||
                $state['button_count'] >= 3 || !in_array($state['phase'], ['inside', 'duplicate', 'card'], true) ||
                ($kind === 'card') !== ($state['phase'] === 'card')) { return Trial::privateReply('この操作は今回のテスト段階では受け付けません。'); }
            $step = $cleanup ? 'cleanup' : $state['phase'];
            $headers = ['x-signature-ed25519' => $server['HTTP_X_SIGNATURE_ED25519'] ?? '', 'x-signature-timestamp' => $server['HTTP_X_SIGNATURE_TIMESTAMP'] ?? ''];
            $response = (new InteractionController($config, $queue, $clock, true))->handle('POST', $headers, $raw);
            if ($response->interactionId === null) { return $response; }
            $pdo->prepare("UPDATE interaction_jobs SET display_name='本人テスト' WHERE interaction_id=?")->execute([$data['id']]);
            $pdo->prepare("INSERT INTO trial_operations(id,step,status) VALUES(?,?,'admitted')")->execute([$data['id'], $step]);
            $state[$kind === 'check' ? 'check_count' : 'button_count']++;
            $state['pending'] = $data['id']; $state['phase'] = 'processing';
            $pdo->prepare('UPDATE trial_meta SET data=? WHERE id=1')->execute([json_encode($state, JSON_THROW_ON_ERROR)]);
            return $response;
        });
    }

    public function ack(string $id, string $bucket): void
    {
        $this->db->pdo()->prepare('UPDATE trial_operations SET ack=1,elapsed_bucket=? WHERE id=? AND status=\'admitted\'')->execute([$bucket, $id]);
    }
    public function start(string $id): bool
    {
        $q = $this->db->pdo()->prepare("UPDATE trial_operations SET status='running' WHERE id=? AND status='admitted' AND ack=1"); $q->execute([$id]);
        return $q->rowCount() === 1;
    }
    public function recordPresence(string $id, PresenceDiagnostic $diagnostic): void
    {
        // One immutable observation for this running, formally acknowledged job.
        // The existing job ID binds the update; the diagnostic contains no
        // request/response, additional identity, header or exception value.
        $this->db->transaction(function (PDO $pdo) use ($id, $diagnostic): void {
            if ($this->state()['pending'] !== $id) { throw new \RuntimeException('trial_presence_record_unavailable'); }
            $statement = $pdo->prepare("UPDATE trial_operations SET presence_http_status=?,presence_classification=? WHERE id=? AND status='running' AND ack=1 AND step IN ('inside','duplicate') AND presence_classification IS NULL");
            $statement->execute([$diagnostic->httpStatus, $diagnostic->classification, $id]);
            if ($statement->rowCount() !== 1) { throw new \RuntimeException('trial_presence_record_unavailable'); }
        });
    }
    public function presenceRecorded(string $id): bool
    {
        $operation = $this->operation($id);
        return $operation !== null && is_string($operation['presence_classification'] ?? null);
    }
    public function consumeRequest(string $id, Clock $clock, bool $cleanupRequest): bool
    {
        return $this->db->transaction(function (PDO $pdo) use ($id, $clock, $cleanupRequest): bool {
            $state = $this->state(); $op = $this->operation($id);
            if ($clock->now()->getTimestamp() >= $state['overall_deadline_at']) { return false; }
            if ($state['api_suspended'] || $state['pending'] !== $id || $op === null || $op['status'] !== 'running' || (int)$op['ack'] !== 1 || (int)$op['requests'] >= ($op['step'] === 'check' ? 12 : 4)) { return false; }
            if (!$cleanupRequest && $clock->now()->getTimestamp() >= $state['expires_at']) { return false; }
            $pdo->prepare('UPDATE trial_operations SET requests=requests+1 WHERE id=?')->execute([$id]); return true;
        });
    }

    public function seed(Clock $clock): void
    {
        $this->db->transaction(function (PDO $pdo) use ($clock): void {
            $state = $this->state();
            if ($state['seeded'] || $this->counts()['attendance'] !== 0 || $clock->now()->getTimestamp() > $state['expires_at'] - Window::VERIFY_SECONDS) { throw new \RuntimeException('trial_seed_rejected'); }
            $scheduled = \DateTimeImmutable::createFromFormat('!Y-m-d_Hi', $state['session'], new \DateTimeZone('Asia/Tokyo'));
            $now = $clock->now()->getTimestamp();
            $pdo->prepare("INSERT INTO sessions(id,scheduled_at,count_date,opens_at,unstarted_closes_at,status,started_at,started_by,closes_at) VALUES(?,?,?,?,?,'started',?,?,?)")
                ->execute([$state['session'], $scheduled->getTimestamp(), substr($state['session'], 0, 10), $now, $state['expires_at'], $now, PrivateConfig::OWNER_ID, min($now + 900, $state['expires_at'])]);
            $state['seeded'] = true;
            $pdo->prepare('UPDATE trial_meta SET data=? WHERE id=1')->execute([json_encode($state, JSON_THROW_ON_ERROR)]);
        });
    }
    public function counts(): array
    {
        $pdo = $this->db->pdo(); $state = $this->state();
        $month = $pdo->prepare('SELECT COUNT(*) FROM attendance WHERE substr(count_date,1,7)=?'); $month->execute([$state['month']]);
        return ['attendance' => (int)$pdo->query('SELECT COUNT(*) FROM attendance')->fetchColumn(),
            'total' => (int)$pdo->query('SELECT COALESCE(SUM(total_count),0) FROM participants')->fetchColumn(), 'month' => (int)$month->fetchColumn(),
            'awards' => (int)$pdo->query('SELECT COUNT(*) FROM memorial_awards')->fetchColumn(), 'effects' => (int)$pdo->query('SELECT COUNT(*) FROM core_effects')->fetchColumn()];
    }
    public function finish(string $id, string $phase, string $code, bool $followup, bool $image): void
    {
        $this->db->transaction(function (PDO $pdo) use ($id, $phase, $code, $followup, $image): void {
            $state = $this->state();
            $pdo->prepare('UPDATE trial_operations SET status=\'done\',code=?,followup=?,image=?,outcome=? WHERE id=?')->execute([Trial::safeCode($code), (int)$followup, (int)$image, json_encode($this->counts(), JSON_THROW_ON_ERROR), $id]);
            // The ordinary worker may mark a retry, but this trial never retries it.
            $pdo->prepare("UPDATE interaction_jobs SET token_ciphertext='',state=CASE WHEN state='done' THEN state ELSE 'failed' END WHERE interaction_id=?")->execute([$id]);
            if ($state['pending'] === $id) { $state['pending'] = null; $state['phase'] = $phase; }
            $pdo->prepare('UPDATE trial_meta SET data=? WHERE id=1')->execute([json_encode($state, JSON_THROW_ON_ERROR)]);
        });
        $this->export();
    }
    public function export(): void
    {
        $state = $this->state(); $rows = [];
        foreach ($this->db->pdo()->query('SELECT step,status,code,ack,followup,image,elapsed_bucket,requests,outcome,presence_http_status,presence_classification FROM trial_operations ORDER BY rowid') as $row) {
            $rows[] = ['step' => $row['step'], 'status' => $row['status'], 'code' => $row['code'], 'ack_finished' => (bool)$row['ack'],
                'followup_ok' => (bool)$row['followup'], 'image_ok' => (bool)$row['image'], 'before_finish_bucket' => $row['elapsed_bucket'],
                'rest_calls' => (int)$row['requests'], 'counts' => $row['outcome'] === null ? null : json_decode($row['outcome'], true, 8, JSON_THROW_ON_ERROR),
                'presence' => $row['presence_classification'] === null ? null : ['http_status' => $row['presence_http_status'] === null ? null : (int)$row['presence_http_status'], 'classification' => $row['presence_classification']]];
        }
        $report = ['schema' => 2, 'build' => Trial::NAME, 'phase' => $state['phase'], 'counts' => $this->counts(), 'post_attempted' => $state['post_attempted'],
            'message_confirmed' => $state['message_id'] !== null, 'cleanup' => $state['cleanup'], 'operations' => $rows];
        $path = $this->root . DIRECTORY_SEPARATOR . 'report.safe.json';
        if (is_link($path) || (file_exists($path) && !is_file($path))) { throw new \RuntimeException('trial_report_unavailable'); }
        if (file_put_contents($path, json_encode($report, JSON_THROW_ON_ERROR) . "\n", LOCK_EX) === false) { throw new \RuntimeException('trial_report_unavailable'); }
        chmod($path, 0600);
    }
}
