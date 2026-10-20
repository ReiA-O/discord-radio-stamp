<?php
declare(strict_types=1);
namespace RadioStamp\Operations;

use PDO;
use RadioStamp\Core\Database;

/** Short cooperative worker for cron. No background launch, network or configuration loading. */
final class PeriodicWorker
{
    private \Closure $wall;
    private \Closure $monotonic;
    private \Closure $sleep;
    public function __construct(private Database $db,private \Closure $tick,private bool $enabled=false,?callable $wall=null,?callable $monotonic=null,?callable $sleep=null)
    {
        $this->wall=$wall===null?static fn():float=>microtime(true):\Closure::fromCallable($wall);
        $this->monotonic=$monotonic===null?static fn():float=>hrtime(true)/1e9:\Closure::fromCallable($monotonic);
        $this->sleep=$sleep===null?static fn(float $seconds)=>usleep((int)($seconds*1000000)):\Closure::fromCallable($sleep);
        $db->pdo()->exec('CREATE TABLE IF NOT EXISTS worker_leases(name TEXT PRIMARY KEY,owner TEXT NOT NULL,expires_at REAL NOT NULL)');
    }
    /** Callback receives a guard to check before every bounded external request. */
    public function run(float $seconds=50,float $interval=1):array
    {
        if($seconds<1||$seconds>55||$interval<0.1||$interval>5){throw new \InvalidArgumentException('worker_budget_invalid');}
        if(!$this->enabled){return ['ok'=>false,'code'=>'worker_disabled','ticks'=>0];}
        $owner=bin2hex(random_bytes(16));$end=($this->monotonic)()+$seconds;
        $claimed=$this->db->transaction(function(PDO $pdo)use($owner):bool{
            $q=$pdo->prepare('SELECT expires_at FROM worker_leases WHERE name=?');$q->execute(['radio']);$expires=$q->fetchColumn();
            if($expires!==false&&(float)$expires>($this->wall)()){return false;}
            $pdo->prepare('INSERT INTO worker_leases(name,owner,expires_at) VALUES(?,?,?) ON CONFLICT(name) DO UPDATE SET owner=excluded.owner,expires_at=excluded.expires_at')->execute(['radio',$owner,($this->wall)()+120]);return true;
        });
        if(!$claimed){return ['ok'=>false,'code'=>'worker_busy','ticks'=>0];}
        $guard=function()use($owner,$end):bool{
            if(($this->monotonic)()>=$end){return false;}
            $q=$this->db->pdo()->prepare('SELECT 1 FROM worker_leases WHERE name=? AND owner=? AND expires_at>?');$q->execute(['radio',$owner,($this->wall)()]);
            return $q->fetchColumn()!==false;
        };
        $ticks=0;$code='worker_complete';
        try{
            while($guard()){
                $continue=($this->tick)($guard);$ticks++;
                if($continue===false){$code='worker_idle';break;}
                $remaining=$end-($this->monotonic)();
                if($remaining<=0){break;}
                ($this->sleep)(min($interval,$remaining));
            }
            if(($this->monotonic)()>$end+2){$code='worker_overrun';}
            elseif(($this->monotonic)()<$end&&$code!=='worker_idle'){$code='worker_lease_lost';}
        }catch(\Throwable){$code='worker_tick_failed';}
        finally{
            $this->db->transaction(fn(PDO $pdo)=>$pdo->prepare('DELETE FROM worker_leases WHERE name=? AND owner=?')->execute(['radio',$owner]));
        }
        return ['ok'=>in_array($code,['worker_complete','worker_idle'],true),'code'=>$code,'ticks'=>$ticks,'max_seconds'=>$seconds,'cooperative_budget'=>true];
    }
}
