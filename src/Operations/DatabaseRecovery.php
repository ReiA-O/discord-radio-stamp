<?php
declare(strict_types=1);
namespace RadioStamp\Operations;

use PDO;
use RadioStamp\Core\Database;
use RadioStamp\Http\PrivateConfig;

/** Consistent SQLite snapshots; restore only to a new private file, never over the active database. */
final class DatabaseRecovery
{
    private string $root;
    public function __construct(private Database $db,string $privateRoot,private string $publicRoot)
    {
        $root=realpath($privateRoot);
        if($root===false||!is_dir($root)||is_link($privateRoot)){throw new \InvalidArgumentException('recovery_private_root_invalid');}
        PrivateConfig::requirePrivatePath($root.'/recovery-probe.sqlite',$publicRoot);
        $this->root=$root;
    }
    private function folder(string $name):string
    {
        $path=$this->root.DIRECTORY_SEPARATOR.$name;
        if(is_link($path)){throw new \RuntimeException('recovery_storage_invalid');}
        if(!is_dir($path)&&!mkdir($path,0700)){throw new \RuntimeException('recovery_storage_unavailable');}
        $resolved=realpath($path);
        if($resolved===false||strtolower(str_replace('\\','/',dirname($resolved)))!==strtolower(str_replace('\\','/',$this->root))){throw new \RuntimeException('recovery_storage_invalid');}
        return $resolved;
    }
    private function file(string $folder,string $name):string
    {
        if(preg_match('/\A[A-Za-z0-9_-]{1,100}\.sqlite\z/D',$name)!==1){throw new \InvalidArgumentException('recovery_name_invalid');}
        $path=$this->folder($folder).DIRECTORY_SEPARATOR.$name;
        PrivateConfig::requirePrivatePath($path,$this->publicRoot);
        return $path;
    }
    private static function inspect(string $path):void
    {
        $handle=fopen($path,'rb');
        if($handle===false){throw new \RuntimeException('recovery_validation_failed');}
        try{$magic=fread($handle,16);}finally{fclose($handle);}
        if($magic!=="SQLite format 3\0"){throw new \RuntimeException('recovery_validation_failed');}
        $check=new PDO('sqlite:'.$path,null,null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
        $check->exec('PRAGMA query_only=ON');$check->exec('PRAGMA trusted_schema=OFF');
        if($check->query('PRAGMA integrity_check')->fetchColumn()!=='ok'||$check->query('PRAGMA foreign_key_check')->fetch()!==false){throw new \RuntimeException('recovery_validation_failed');}
    }
    private function snapshot(string $source,string $target):void
    {
        $input=null;$output=null;
        try{
            // The native online backup API predates SQLite 3.26. It reads committed
            // pages, including WAL, without copying a live DB/WAL pair or using VACUUM INTO.
            $input=new \SQLite3($source,SQLITE3_OPEN_READONLY);$input->enableExceptions(true);$input->busyTimeout(5000);
            $output=new \SQLite3($target,SQLITE3_OPEN_READWRITE);$output->enableExceptions(true);$output->busyTimeout(5000);
            if(!$input->backup($output,'main','main')){throw new \RuntimeException('recovery_snapshot_failed');}
        }finally{
            if($output!==null){$output->close();}
            if($input!==null){$input->close();}
        }
    }
    public function backup():array
    {
        if(!class_exists(\SQLite3::class)||!method_exists(\SQLite3::class,'backup')){throw new \RuntimeException('recovery_backup_api_unavailable');}
        if($this->db->inTransaction()){throw new \RuntimeException('recovery_transaction_active');}
        $source=null;
        foreach($this->db->pdo()->query('PRAGMA database_list')->fetchAll(PDO::FETCH_ASSOC)as$database){if($database['name']==='main'){$source=$database['file'];}}
        if(!is_string($source)||$source===''||!is_file($source)){throw new \RuntimeException('recovery_source_invalid');}
        PrivateConfig::requirePrivatePath($source,$this->publicRoot);
        $name='radio-'.gmdate('YmdHis').'-'.bin2hex(random_bytes(8)).'.sqlite';
        $path=$this->file('backups',$name);
        if(file_exists($path)||is_link($path)){throw new \RuntimeException('recovery_destination_exists');}
        $manifest=$path.'.manifest.json';$created=false;$manifestCreated=false;
        try{
            // SQLite's backup API overwrites its destination. Reserve a new private
            // file exclusively first, so an existing database can never be the target.
            $reserved=fopen($path,'x+b');if($reserved===false){throw new \RuntimeException('recovery_destination_exists');}
            $created=true;fclose($reserved);@chmod($path,0600);
            $this->snapshot($source,$path);self::inspect($path);
            $result=['schema'=>1,'method'=>'sqlite_online_backup','file'=>$name,'bytes'=>filesize($path),'sha256'=>hash_file('sha256',$path),'created_utc'=>gmdate('c')];
            $handle=fopen($manifest,'x+b');if($handle===false){throw new \RuntimeException('recovery_manifest_unavailable');}
            $manifestCreated=true;@chmod($manifest,0600);
            try{$json=json_encode($result,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR)."\n";if(fwrite($handle,$json)!==strlen($json)){throw new \RuntimeException('recovery_manifest_unavailable');}}finally{fclose($handle);}
            return $result+['ok'=>true,'consistent_snapshot'=>true,'contains_credentials_in_output'=>false];
        }catch(\Throwable){
            // Only this newly generated, checked private file is removed; the active DB is untouched.
            if($manifestCreated&&is_file($manifest)&&!is_link($manifest)){unlink($manifest);}
            if($created&&is_file($path)&&!is_link($path)){unlink($path);}
            throw new \RuntimeException('recovery_backup_failed');
        }
    }
    public function restoreNew(string $backupName):array
    {
        $source=$this->file('backups',$backupName);
        $manifest=$source.'.manifest.json';
        if(!is_file($source)||is_link($manifest)||!is_file($manifest)){throw new \RuntimeException('recovery_backup_missing');}
        $size=filesize($manifest);if($size===false||$size>4096){throw new \RuntimeException('recovery_manifest_invalid');}
        try{$proof=json_decode(file_get_contents($manifest),true,8,JSON_THROW_ON_ERROR);}catch(\Throwable){throw new \RuntimeException('recovery_manifest_invalid');}
        if(($proof['schema']??null)!==1||($proof['file']??null)!==$backupName||!is_string($proof['sha256']??null)
            ||preg_match('/\A[0-9a-f]{64}\z/D',$proof['sha256'])!==1||($proof['bytes']??null)!==filesize($source)
            ||!hash_equals($proof['sha256'],hash_file('sha256',$source))){throw new \RuntimeException('recovery_backup_mismatch');}
        self::inspect($source);
        $name='restored-'.gmdate('YmdHis').'-'.bin2hex(random_bytes(8)).'.sqlite';
        $target=$this->file('restores',$name);
        $output=fopen($target,'x+b');if($output===false){throw new \RuntimeException('recovery_destination_exists');}
        try{
            $input=fopen($source,'rb');if($input===false){throw new \RuntimeException('recovery_copy_failed');}
            try{if(stream_copy_to_stream($input,$output)!==$proof['bytes']){throw new \RuntimeException('recovery_copy_failed');}}finally{fclose($input);}
            fclose($output);$output=null;@chmod($target,0600);
            if(!hash_equals($proof['sha256'],hash_file('sha256',$target))){throw new \RuntimeException('recovery_copy_failed');}
            self::inspect($target);
            return ['ok'=>true,'file'=>$name,'sha256'=>$proof['sha256'],'active_database_replaced'=>false,'activation_required'=>true,'notification_states_preserved'=>true];
        }catch(\Throwable){
            if(is_resource($output)){fclose($output);}
            if(is_file($target)&&!is_link($target)){unlink($target);}
            throw new \RuntimeException('recovery_restore_failed');
        }
    }
}
