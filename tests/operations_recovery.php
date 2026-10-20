<?php
declare(strict_types=1);
require_once __DIR__.'/artifact_directory.php';
require dirname(__DIR__).'/bootstrap.php';
use RadioStamp\Core\Database;
use RadioStamp\Operations\{DatabaseRecovery,PeriodicWorker};

$checks=0;$same=static function(mixed $expected,mixed $actual,string $why)use(&$checks):void{++$checks;if($expected!==$actual){throw new RuntimeException('Operations recovery: '.$why);}};
$reject=static function(callable $call):bool{try{$call();return false;}catch(Throwable){return true;}};
if(($argv[1]??'')==='--writer'){
    $db=new Database($argv[2]);
    $db->transaction(function(PDO $pdo):void{
        $pdo->exec('UPDATE recovery_counter SET value=value+1');
        // The parent snapshots with an uncommitted half of this transaction in WAL.
        echo "writer ready\n";fflush(STDOUT);
        if(trim((string)fgets(STDIN))!=='commit'){throw new RuntimeException('Writer synchronization failed');}
        $pdo->exec('INSERT INTO recovery_events DEFAULT VALUES');
    });
    for($i=1;$i<100;$i++){$db->transaction(function(PDO $pdo):void{$pdo->exec('UPDATE recovery_counter SET value=value+1');$pdo->exec('INSERT INTO recovery_events DEFAULT VALUES');});usleep(1000);}
    echo 'writer complete';exit;
}
$db=new Database(':memory:');$wall=1000.0;$mono=0.0;$calls=0;
$wallNow=static function()use(&$wall):float{return $wall;};$monoNow=static function()use(&$mono):float{return $mono;};
$sleep=static function(float $seconds)use(&$wall,&$mono):void{$wall+=$seconds;$mono+=$seconds;};
$disabled=new PeriodicWorker($db,static function(){throw new RuntimeException('must not run');});
$same('worker_disabled',$disabled->run()['code'],'periodic worker defaults disabled');
$nested=null;
$worker=new PeriodicWorker($db,function(Closure $guard)use(&$calls,&$nested,$db,$wallNow,$monoNow,$sleep,$same):void{
    $same(true,$guard(),'callback sees valid fenced budget');$calls++;
    if($calls===1){$nested=(new PeriodicWorker($db,static function(){throw new RuntimeException('overlap');},true,$wallNow,$monoNow,$sleep))->run(1,1);}
},true,$wallNow,$monoNow,$sleep);
$result=$worker->run(3,1);$same(true,$result['ok'],'bounded polling completes');$same(3,$calls,'one tick per interval');
$same('worker_busy',$nested['code'],'second worker cannot overlap');
$same(0,(int)$db->pdo()->query('SELECT COUNT(*) FROM worker_leases')->fetchColumn(),'lease released on normal completion');
$db->pdo()->exec("INSERT INTO worker_leases VALUES('radio','crashed-worker',1010)");
$same('worker_busy',$worker->run(1,1)['code'],'unexpired crash lease held');
$wall=1011;$mono=0;$same(true,$worker->run(1,1)['ok'],'expired crash lease can be reclaimed');
$mono=0;
$lost=new PeriodicWorker($db,function(Closure $guard)use($db):void{$db->pdo()->exec("UPDATE worker_leases SET owner='replacement'");},true,$wallNow,$monoNow,$sleep);
$same('worker_lease_lost',$lost->run(2,1)['code'],'lost lease stops polling');
$same('replacement',$db->pdo()->query('SELECT owner FROM worker_leases')->fetchColumn(),'stale worker cannot release replacement lease');
$db->pdo()->exec('DELETE FROM worker_leases');$mono=0;
$failed=new PeriodicWorker($db,static function(){throw new RuntimeException('fictional_sensitive_error');},true,$wallNow,$monoNow,$sleep);
$result=$failed->run(1,1);$same('worker_tick_failed',$result['code'],'callback failure stops');
$same(false,str_contains(json_encode($result),'fictional_sensitive_error'),'error text not exposed');
$same(0,(int)$db->pdo()->query('SELECT COUNT(*) FROM worker_leases')->fetchColumn(),'failure releases lease');
$mono=0;
$overrun=new PeriodicWorker($db,static function()use(&$mono):void{$mono=100;},true,$wallNow,$monoNow,$sleep);
$same('worker_overrun',$overrun->run(1,1)['code'],'cooperative overrun is explicit, not a hard-stop guarantee');
$same(true,$reject(fn()=>$worker->run(60,1)),'worker cannot request more than 55 seconds');
$same(true,$reject(fn()=>$worker->run(5,0)),'invalid polling interval refused');

$root=sys_get_temp_dir().'/radio-operations-'.bin2hex(random_bytes(8));mkdir($root,0700);mkdir($root.'/public',0700);
$resolvedRoot=realpath($root);$tempRoot=realpath(sys_get_temp_dir());
if($resolvedRoot===false||$tempRoot===false||!str_starts_with(strtolower(str_replace('\\','/',$resolvedRoot)),rtrim(strtolower(str_replace('\\','/',$tempRoot)),'/').'/')){throw new RuntimeException('Test cleanup root invalid');}
$files=[];$source=$root.'/application.sqlite';$restored=null;$snapshot=null;$recovery=null;
try{
    $db=new Database($source);
    $db->pdo()->exec('CREATE TABLE recovery_counter(value INTEGER NOT NULL);INSERT INTO recovery_counter VALUES(1);CREATE TABLE recovery_events(id INTEGER PRIMARY KEY);INSERT INTO recovery_events DEFAULT VALUES');
    $db->pdo()->exec("CREATE TABLE notification_test(id INTEGER PRIMARY KEY,status TEXT NOT NULL);INSERT INTO notification_test VALUES(1,'sending'),(2,'uncertain')");
    $db->pdo()->exec('PRAGMA user_version=326;PRAGMA application_id=1381184592;CREATE TABLE recovery_payload(id INTEGER PRIMARY KEY AUTOINCREMENT,body BLOB NOT NULL,label TEXT NOT NULL UNIQUE);CREATE INDEX payload_label ON recovery_payload(label);CREATE TABLE recovery_audit(id INTEGER PRIMARY KEY);CREATE TRIGGER payload_audit AFTER INSERT ON recovery_payload BEGIN INSERT INTO recovery_audit VALUES(new.id);END;CREATE VIEW payload_view AS SELECT id,label FROM recovery_payload');
    $payload=$db->pdo()->prepare('INSERT INTO recovery_payload(body,label) VALUES(?,?)');$payload->bindValue(1,"\0\xFF\x01\0",PDO::PARAM_LOB);$payload->bindValue(2,"体操\0記念",PDO::PARAM_STR);$payload->execute();unset($payload);
    $recovery=new DatabaseRecovery($db,$root,$root.'/public');
    $same(true,$reject(fn()=>new DatabaseRecovery($db,$root.'/public',$root.'/public')),'backup storage cannot be public');
    $same(true,is_file($source.'-wal')&&filesize($source.'-wal')>0,'committed data exists in live WAL before backup');
    $same(false,$db->inTransaction(),'source begins outside transaction');
    $db->transaction(function(PDO $pdo)use($db,$recovery,$same,$reject):void{
        $pdo->exec('UPDATE recovery_counter SET value=999');
        $same(true,$db->inTransaction(),'manual BEGIN IMMEDIATE tracked by core wrapper');
        $same(true,$reject(fn()=>$recovery->backup()),'backup within core transaction refused');
        $pdo->exec('UPDATE recovery_counter SET value=1');
    });
    $db->pdo()->beginTransaction();$same(true,$reject(fn()=>$recovery->backup()),'PDO transaction backup refused');$db->pdo()->rollBack();
    $backup=$recovery->backup();$files[]=$root.'/backups/'.$backup['file'];$files[]=$root.'/backups/'.$backup['file'].'.manifest.json';
    $same('sqlite_online_backup',$backup['method'],'native backup API replaces post-3.26 VACUUM INTO SQL');
    $same(true,$backup['consistent_snapshot'],'SQLite makes consistent WAL-aware snapshot');
    $same(false,$backup['contains_credentials_in_output'],'backup metadata contains no row data');
    $same(true,is_file($files[0]),'backup exists only at private generated filename');
    $db->pdo()->exec('UPDATE recovery_counter SET value=2;INSERT INTO recovery_events DEFAULT VALUES');
    $restore=$recovery->restoreNew($backup['file']);$files[]=$root.'/restores/'.$restore['file'];
    $same(false,$restore['active_database_replaced'],'restore never replaces active DB');
    $same(true,$restore['activation_required'],'activation remains explicit');
    $restored=new PDO('sqlite:'.$root.'/restores/'.$restore['file']);
    $same(1,(int)$restored->query('SELECT value FROM recovery_counter')->fetchColumn(),'restored snapshot retains backup state');
    $same(2,(int)$db->pdo()->query('SELECT value FROM recovery_counter')->fetchColumn(),'active database unchanged by restore');
    $same(['sending','uncertain'],$restored->query('SELECT status FROM notification_test ORDER BY id')->fetchAll(PDO::FETCH_COLUMN),'delivery uncertainty not reset into automatic resend');
    $same($backup['sha256'],hash_file('sha256',$root.'/restores/'.$restore['file']),'restored bytes match verified backup');
    $same(326,(int)$restored->query('PRAGMA user_version')->fetchColumn(),'application schema version preserved');
    $same(1381184592,(int)$restored->query('PRAGMA application_id')->fetchColumn(),'application identity preserved');
    $same('00FF0100',$restored->query('SELECT hex(body) FROM recovery_payload')->fetchColumn(),'binary and NUL bytes preserved');
    $same("体操\0記念",$restored->query('SELECT label FROM recovery_payload')->fetchColumn(),'Unicode and embedded NUL text preserved');
    $same('blob',$restored->query('SELECT typeof(body) FROM recovery_payload')->fetchColumn(),'SQLite storage class preserved');
    $same(1,(int)$restored->query('SELECT COUNT(*) FROM payload_view')->fetchColumn(),'view schema preserved');
    $same(1,(int)$restored->query("SELECT COUNT(*) FROM sqlite_master WHERE type='index' AND name='payload_label'")->fetchColumn(),'index schema preserved');
    $restored->exec("INSERT INTO recovery_payload(body,label) VALUES(X'FE','restored-only')");
    $same(2,(int)$restored->query('SELECT COUNT(*) FROM recovery_audit')->fetchColumn(),'trigger operates in separate restored file');
    $same(2,(int)$restored->query("SELECT seq FROM sqlite_sequence WHERE name='recovery_payload'")->fetchColumn(),'autoincrement sequence retained');
    $same(1,(int)$db->pdo()->query('SELECT COUNT(*) FROM recovery_audit')->fetchColumn(),'restored verification write does not touch source');
    $second=$recovery->restoreNew($backup['file']);$files[]=$root.'/restores/'.$second['file'];
    $same(false,$second['file']===$restore['file'],'another restore creates a new candidate, no overwrite');
    $same(true,$reject(fn()=>$recovery->restoreNew('../application.sqlite')),'path traversal refused');
    $same(true,$reject(fn()=>$recovery->restoreNew('application.sqlite')),'arbitrary active DB cannot be named as backup');
    $memory=new Database(':memory:');$same(true,$reject(fn()=>(new DatabaseRecovery($memory,$root,$root.'/public'))->backup()),'unrelated memory database cannot silently become an empty snapshot');unset($memory);

    // Backup while another local PHP process writes two related tables transactionally.
    $command=[PHP_BINARY];if(($ini=php_ini_loaded_file())!==false){$command[]='-c';$command[]=$ini;}array_push($command,__FILE__,'--writer',$source);
    $pipes=[];$process=proc_open($command,[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes,dirname(__DIR__));
    if(!is_resource($process)){throw new RuntimeException('Cannot start backup writer');}
    try{
        $same("writer ready\n",fgets($pipes[1]),'writer is inside a partial uncommitted transaction before snapshot');
        $concurrent=$recovery->backup();$files[]=$root.'/backups/'.$concurrent['file'];$files[]=$root.'/backups/'.$concurrent['file'].'.manifest.json';
    }finally{
        fwrite($pipes[0],"commit\n");fclose($pipes[0]);
        stream_get_contents($pipes[1]);$error=stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);$writerExit=proc_close($process);
    }
    $same(0,$writerExit,'concurrent writer exits: '.$error);
    $snapshot=new PDO('sqlite:'.$root.'/backups/'.$concurrent['file']);
    $same((int)$snapshot->query('SELECT value FROM recovery_counter')->fetchColumn(),(int)$snapshot->query('SELECT COUNT(*) FROM recovery_events')->fetchColumn(),'concurrent snapshot has matching transactional tables');
    $same(2,(int)$snapshot->query('SELECT value FROM recovery_counter')->fetchColumn(),'uncommitted partial write is excluded from snapshot');
    $same(102,(int)$db->pdo()->query('SELECT value FROM recovery_counter')->fetchColumn(),'live writes continue independently');
    $same('ok',$snapshot->query('PRAGMA integrity_check')->fetchColumn(),'concurrent snapshot integrity');
    file_put_contents($root.'/backups/'.$backup['file'],"x",FILE_APPEND);
    $same(true,$reject(fn()=>$recovery->restoreNew($backup['file'])),'tampered backup rejected before restore');
    $same(102,(int)$db->pdo()->query('SELECT value FROM recovery_counter')->fetchColumn(),'failed restore still leaves live DB alone');
}finally{
    unset($snapshot,$restored,$recovery,$db);gc_collect_cycles();
    foreach(array_reverse($files)as$file){if(is_file($file)&&!is_link($file)){unlink($file);}}
    foreach([$source,$source.'-wal',$source.'-shm']as$file){if(is_file($file)&&!is_link($file)){unlink($file);}}
    foreach(['restores','backups','public']as$directory){if(is_dir($root.'/'.$directory)){rmdir($root.'/'.$directory);}}rmdir($root);
}
echo "Operations recovery: {$checks} assertions passed (cooperative worker leases; private SQLite snapshots and new-file restore; no live operations).\n";
