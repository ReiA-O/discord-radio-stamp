<?php
declare(strict_types=1);
namespace RadioStamp\Operations;

use RadioStamp\Core\Database;

/** Code-injected runtime is only for offline tests; command-line input cannot select an adapter. */
final class OperationsCli
{
    public function __construct(private OperationsRuntime $runtime){}
    public function run(array $args,\Closure $emit):int
    {
        $options=[];$action=null;
        foreach($args as$argument){
            if(in_array($argument,['--run','--once','--register','--backup','--dry-run'],true)){
                if($action!==null){return $this->result($emit,false,'one_action_required',2);}$action=substr($argument,2);
            }elseif(str_starts_with($argument,'--restore=')){
                if($action!==null){return $this->result($emit,false,'one_action_required',2);}$action='restore';$options['restore']=substr($argument,10);
            }elseif(preg_match('/\A--(config|public-root|duration)=(.+)\z/D',$argument,$m)){$options[$m[1]]=$m[2];}
            else{return $this->result($emit,false,'unknown_option',2);}
        }
        if($action===null||!isset($options['config'],$options['public-root'])){return $this->result($emit,false,'config_public_root_and_action_required',2);}
        $context=null;
        try{
            $public=$options['public-root'];
            $config=OperationsConfig::load($options['config'],$public,!in_array($action,['backup','restore','dry-run'],true));
            if($action==='dry-run'){return $this->result($emit,OperationsConfig::ready($config),OperationsConfig::ready($config)?'configuration_ready':'configuration_disabled_or_incomplete',OperationsConfig::ready($config)?0:1);}
            if($action==='backup'||$action==='restore'){
                if($action==='backup'&&!is_file($config['private_db_path'])){return $this->result($emit,false,'active_database_missing',1);}
                $db=$action==='backup'?new Database($config['private_db_path'],5000,false):new Database(':memory:');
                $recovery=new DatabaseRecovery($db,$config['private_root'],$public);
                $result=$action==='backup'?$recovery->backup():$recovery->restoreNew($options['restore']);
                $emit(json_encode($result,JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR)."\n");return 0;
            }
            if($action==='register'&&!$config['command_registration_enabled']){return $this->result($emit,false,'command_registration_disabled',1);}
            if(in_array($action,['run','once'],true)&&!$config['periodic_worker_enabled']){return $this->result($emit,false,'periodic_worker_disabled',1);}
            $duration=isset($options['duration'])&&preg_match('/\A(?:[1-9]|[1-4][0-9]|5[0-5])\z/D',$options['duration'])?(int)$options['duration']:50;
            if(isset($options['duration'])&&(string)$duration!==$options['duration']){return $this->result($emit,false,'worker_budget_invalid',2);}
            $end=hrtime(true)/1e9+($action==='register'?30:($action==='once'?20:$duration));$workerGuard=static fn():bool=>true;
            $guard=static function()use($end,&$workerGuard):bool{return hrtime(true)/1e9<$end&&$workerGuard();};
            $context=$this->runtime->build($config,$guard,$end);
            if($action==='register'){
                if(!$context->client->identity()->ok){return $this->result($emit,false,'operations_identity_unverified',1);}
                $registered=$context->client->registerParticipantCommands();return $this->result($emit,$registered->ok,$registered->code,$registered->ok?0:1);
            }
            if($action==='once'){$cycle=$this->runtime->cycle($context,$guard);$emit(json_encode($cycle,JSON_THROW_ON_ERROR)."\n");return $cycle['ok']?0:1;}
            $codes=[];$sessionCodes=[];$sessionFailures=0;
            $loop=new PeriodicWorker($context->db,function(\Closure $leaseGuard)use($context,&$workerGuard,&$codes,&$sessionCodes,&$sessionFailures):bool{
                $workerGuard=$leaseGuard;$cycle=$this->runtime->cycle($context,$leaseGuard);$codes[]=$cycle['code'];
                foreach($cycle['session_results']??[]as$session){$sessionCodes[]=SafeDiagnostics::code($session['code']??null);if(($session['ok']??false)!==true){$sessionFailures++;}}
                return $cycle['ok']&&$this->runtime->pollNeeded($context);
            },true);
            $result=$loop->run($duration,1);$result['cycle_codes']=array_values(array_unique($codes));
            $result['session_codes']=array_values(array_unique($sessionCodes));$result['session_failures']=$sessionFailures;
            $emit(json_encode($result,JSON_THROW_ON_ERROR)."\n");return $result['ok']?0:1;
        }catch(\Throwable $error){$context?->diagnostics?->exception('cycle',$error);return $this->result($emit,false,'operations_unavailable',1);}
        finally{$context?->roster->close();}
    }
    private function result(\Closure $emit,bool $ok,string $code,int $exit):int{$emit(json_encode(['ok'=>$ok,'code'=>$code],JSON_THROW_ON_ERROR)."\n");return $exit;}
}
