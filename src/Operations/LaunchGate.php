<?php
declare(strict_types=1);
namespace RadioStamp\Operations;

use RadioStamp\Core\{Clock,Schedule};
use RadioStamp\Http\PrivateConfig;

/** Nonsecret sidecar checked before loading the credential-bearing configuration. */
final readonly class LaunchGate
{
    private function __construct(public int $firstSessionAt){}
    public static function read(string $configPath,string $publicRoot):?self
    {
        $path=dirname($configPath).DIRECTORY_SEPARATOR.'launch-gate.json';
        PrivateConfig::requirePrivatePath($path,$publicRoot);
        if(!file_exists($path)&&!is_link($path)){return null;} // Legacy configurations remain unchanged.
        if(is_link($path)||!is_file($path)){throw new \RuntimeException('launch_gate_unavailable');}
        $before=@lstat($path);$raw=@file_get_contents($path,false,null,0,513);clearstatcache(true,$path);$after=@lstat($path);
        if(!is_array($before)||!is_array($after)||!is_string($raw)||strlen($raw)>512||$before['size']!==strlen($raw)) {throw new \RuntimeException('launch_gate_unavailable');}
        if(($before['nlink']??null)!==1||(DIRECTORY_SEPARATOR==='/'&&((($before['mode']&0777)!==0600)||!function_exists('posix_geteuid')||$before['uid']!==posix_geteuid()))){throw new \RuntimeException('launch_gate_unavailable');}
        foreach(['dev','ino','mode','nlink','size','mtime','ctime']as$key){if($before[$key]!==$after[$key]){throw new \RuntimeException('launch_gate_changed');}}
        $data=json_decode($raw,true,4,JSON_THROW_ON_ERROR);
        if(!is_array($data)||count($data)!==2||array_diff(array_keys($data),['version','first_session_at'])!==[]||($data['version']??null)!==1||!is_string($data['first_session_at']??null)){throw new \RuntimeException('launch_gate_invalid');}
        $schedule=new Schedule(Schedule::DEFAULT_SPEC+['not_before'=>$data['first_session_at']]);
        return new self($schedule->firstSessionAt());
    }
    public function permits(Clock $clock):bool{return $clock->now()->getTimestamp()>=$this->firstSessionAt-1800;}
    public function matches(array $config):bool{return Schedule::fromConfig($config)->firstSessionAt()===$this->firstSessionAt;}
}
