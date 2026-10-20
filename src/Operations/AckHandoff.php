<?php
declare(strict_types=1);
namespace RadioStamp\Operations;

use RadioStamp\Http\{InteractionQueue,PrivateConfig};

/** Private, immutable proof of a successful response finish; never contains callback tokens. */
final class AckHandoff
{
    private string $directory;
    private string $binding;
    private string $key;
    private const MAX_BYTES=4096;

    public function __construct(#[\SensitiveParameter] array $config)
    {
        $root=$config['private_root']??null;$db=$config['private_db_path']??null;
        if(!is_string($root)||!is_string($db)||!is_string($config['token_encryption_key_hex']??null)
            ||preg_match('/\A[0-9a-fA-F]{64}\z/D',$config['token_encryption_key_hex'])!==1){throw new \RuntimeException('ack_handoff_unavailable');}
        $root=$this->canonical($root,true);$db=$this->canonical($db,false);
        if(!str_starts_with($this->normalize($db),rtrim($this->normalize($root),'/').'/')){throw new \RuntimeException('ack_handoff_unavailable');}
        // HTTP/CLI load() sets this trusted internal value. Direct runtime injection is a trusted offline API.
        if(isset($config['_validated_public_root'])){
            if(!is_string($config['_validated_public_root'])){throw new \RuntimeException('ack_handoff_unavailable');}
            PrivateConfig::requirePrivatePath($root.'/ack-handoff-check',$config['_validated_public_root']);
            PrivateConfig::requirePrivatePath($db,$config['_validated_public_root']);
        }
        $this->requireMode($root,0700);
        $directory=$root.DIRECTORY_SEPARATOR.'ack-handoff';
        if(is_link($directory)){throw new \RuntimeException('ack_handoff_unavailable');}
        if(!is_dir($directory)&&!@mkdir($directory,0700)&&!is_dir($directory)){throw new \RuntimeException('ack_handoff_unavailable');}
        $this->directory=$this->canonical($directory,true);$this->requireMode($this->directory,0700);
        $this->binding=hash('sha256','ack-instance-v1'."\0".$this->normalize($db)."\0".($config['application_id']??'')."\0".($config['guild_id']??''));
        $this->key=hash_hmac('sha256','radio-stamp/ack-handoff/v1',hex2bin($config['token_encryption_key_hex']),true);
    }

    public function __debugInfo():array{return ['scope'=>'private_ack_handoff','credentials'=>'[redacted]'];}

    /** Called before ACK: the queue's immutable fields and original expiry are frozen in memory. */
    public function prepare(InteractionQueue $queue,string $id):array
    {
        $ticket=$queue->acknowledgementMetadata($id);
        if($ticket===null||$ticket['scope_mode']!=='operations'){throw new \RuntimeException('ack_handoff_unavailable');}
        unset($ticket['state']);
        return ['version'=>1,'binding'=>$this->binding]+$ticket;
    }

    /** Must only be called by the post-response callback after finish() returned true. No SQLite here. */
    public function recordFinished(array $ticket,int $finishedAt):bool
    {
        $payload=$ticket+['finished_at'=>$finishedAt];
        if(!$this->validPayload($payload,$finishedAt)){return false;}
        $record=['payload'=>$payload,'mac'=>$this->mac($payload)];
        $line=json_encode($record,JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR)."\n";
        if(strlen($line)>self::MAX_BYTES){throw new \RuntimeException('ack_handoff_unavailable');}
        $nonce=bin2hex(random_bytes(16));$temporary=$this->directory.DIRECTORY_SEPARATOR.'.tmp-'.$nonce;
        $final=$this->directory.DIRECTORY_SEPARATOR.$nonce.'.ack.json';
        $this->checkDirectory();$mask=umask(0077);$handle=null;
        try{
            $handle=@fopen($temporary,'x+b');
            if($handle===false){throw new \RuntimeException('ack_handoff_unavailable');}
            if(!@chmod($temporary,0600)){throw new \RuntimeException('ack_handoff_unavailable');}
            $offset=0;
            while($offset<strlen($line)){
                $written=@fwrite($handle,substr($line,$offset));
                if(!is_int($written)||$written<1){throw new \RuntimeException('ack_handoff_unavailable');}
                $offset+=$written;
            }
            if(!@fflush($handle)||!function_exists('fsync')||!@fsync($handle)){throw new \RuntimeException('ack_handoff_unavailable');}
            fclose($handle);$handle=null;$this->requireMode($temporary,0600);$this->checkDirectory();
            if(file_exists($final)||is_link($final)||!@rename($temporary,$final)){throw new \RuntimeException('ack_handoff_unavailable');}
            // File contents are fsynced. Directory fsync is best effort and is not a power-loss guarantee.
            if(PHP_OS_FAMILY!=='Windows'){$directory=@fopen($this->directory,'r');if(is_resource($directory)){@fsync($directory);fclose($directory);}}
            return true;
        }finally{
            if(is_resource($handle)){fclose($handle);}
            if(is_file($temporary)&&!is_link($temporary)){@unlink($temporary);}
            umask($mask);
        }
    }

    /** Import commits before unlink. Parallel import and repeated finish proofs are idempotent. */
    public function recover(InteractionQueue $queue,int $now,int $limit=32):array
    {
        $this->checkDirectory();$result=['recovered'=>0,'rejected'=>0,'expired'=>0,'deferred'=>0];$seen=0;
        $entries=new \FilesystemIterator($this->directory,\FilesystemIterator::SKIP_DOTS);
        foreach($entries as$entry){
            $name=$entry->getFilename();$path=$this->directory.DIRECTORY_SEPARATOR.$name;
            if(preg_match('/\A\.tmp-[0-9a-f]{32}\z/D',$name)===1){
                // Incomplete writes are never proofs. Remove only old regular temporary files.
                $temporary=@lstat($path);
                if($temporary!==false&&!is_link($path)&&($temporary['mode']&0170000)===0100000&&$temporary['mtime']<=$now-900){@unlink($path);}
                continue;
            }
            if(preg_match('/\A[0-9a-f]{32}\.ack\.json\z/D',$name)!==1){continue;}
            if(++$seen>$limit){break;}
            $record=$this->readRecord($path);
            if($record===null){$result['rejected']++;$this->discard($path);continue;}
            $payload=$record['payload'];
            if(!$this->validPayload($payload,$now)||!hash_equals($this->mac($payload),$record['mac'])){
                $result['rejected']++;$this->discard($path);continue;
            }
            if($payload['expires_at']<=$now){$result['expired']++;$this->discard($path);continue;}
            try{$accepted=$queue->confirmAcknowledgement($payload,$now);}
            catch(\PDOException){$result['deferred']++;break;}
            $result[$accepted?'recovered':'rejected']++;
            $this->discard($path);
        }
        return $result;
    }

    private function validPayload(array $payload,int $now):bool
    {
        $keys=['version','binding','instance_id','interaction_id','request_hash','scope_mode','created_at','expires_at','job_hash','finished_at'];
        if(count($payload)!==count($keys)||array_diff(array_keys($payload),$keys)!==[]||($payload['version']??null)!==1
            ||($payload['binding']??null)!==$this->binding||($payload['scope_mode']??null)!=='operations'
            ||!PrivateConfig::snowflake($payload['interaction_id']??null)){return false;}
        foreach(['instance_id','request_hash','job_hash']as$key){if(!is_string($payload[$key]??null)||preg_match('/\A[0-9a-f]{64}\z/D',$payload[$key])!==1){return false;}}
        foreach(['created_at','expires_at','finished_at']as$key){if(!is_int($payload[$key]??null)||$payload[$key]<1){return false;}}
        return $payload['expires_at']===$payload['created_at']+900&&$payload['finished_at']>=$payload['created_at']
            &&$payload['finished_at']<$payload['expires_at']&&$payload['finished_at']<=$now;
    }

    private function mac(array $payload):string
    {
        // Explicit order prevents a parsed object's key order from changing its authentication.
        $ordered=[];foreach(['version','binding','instance_id','interaction_id','request_hash','scope_mode','created_at','expires_at','job_hash','finished_at']as$key){$ordered[$key]=$payload[$key];}
        return hash_hmac('sha256',json_encode($ordered,JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR),$this->key);
    }

    private function readRecord(string $path):?array
    {
        clearstatcache(true,$path);$before=@lstat($path);
        if($before===false||is_link($path)||($before['mode']&0170000)!==0100000||$before['size']<1||$before['size']>self::MAX_BYTES){return null;}
        if(PHP_OS_FAMILY!=='Windows'&&($before['mode']&0777)!==0600){return null;}
        $handle=@fopen($path,'rb');if($handle===false){return null;}
        try{
            $opened=fstat($handle);clearstatcache(true,$path);$current=@lstat($path);
            if($opened===false||$current===false||is_link($path)||($opened['mode']&0170000)!==0100000
                ||$opened['ino']!==$before['ino']||$opened['dev']!==$before['dev']||$current['ino']!==$opened['ino']||$current['dev']!==$opened['dev']){return null;}
            $text=stream_get_contents($handle,self::MAX_BYTES+1);
        }finally{fclose($handle);}
        try{$record=json_decode($text,true,16,JSON_THROW_ON_ERROR);}catch(\Throwable){return null;}
        if(!is_array($record)||array_keys($record)!==['payload','mac']||!is_array($record['payload'])||!is_string($record['mac'])||preg_match('/\A[0-9a-f]{64}\z/D',$record['mac'])!==1){return null;}
        return $record;
    }

    private function discard(string $path):void
    {
        $this->checkDirectory();
        // Only entries with internally generated names, inside this directory. unlink never follows a symlink.
        if(dirname($path)===$this->directory){@unlink($path);}
    }
    private function checkDirectory():void
    {
        if($this->canonical($this->directory,true)!==$this->directory){throw new \RuntimeException('ack_handoff_unavailable');}
        $this->requireMode($this->directory,0700);
    }
    private function canonical(string $path,bool $directory):string
    {
        $path=rtrim(str_replace('\\','/',$path),'/');
        if($path===''||(!str_starts_with($path,'/')&&preg_match('/\A[A-Za-z]:\//',$path)!==1)||preg_match('~(?:^|/)\.{1,2}(?:/|$)~',$path)===1){throw new \RuntimeException('ack_handoff_unavailable');}
        $probe=$path;
        while(true){clearstatcache(true,$probe);if(is_link($probe)){throw new \RuntimeException('ack_handoff_unavailable');}$parent=dirname($probe);if($parent===$probe||$parent==='.')break;$probe=$parent;}
        $real=realpath($path);
        if($real===false||($directory?!is_dir($real):!is_file($real))||$this->normalize($real)!==$this->normalize($path)){throw new \RuntimeException('ack_handoff_unavailable');}
        return $real;
    }
    private function normalize(string $path):string{$path=str_replace('\\','/',$path);return PHP_OS_FAMILY==='Windows'?strtolower($path):$path;}
    private function requireMode(string $path,int $mode):void
    {
        clearstatcache(true,$path);$permissions=@fileperms($path);
        if($permissions===false||(PHP_OS_FAMILY!=='Windows'&&($permissions&0777)!==$mode)){throw new \RuntimeException('ack_handoff_unavailable');}
    }
}
