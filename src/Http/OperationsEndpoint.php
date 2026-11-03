<?php
declare(strict_types=1);
namespace RadioStamp\Http;

use RadioStamp\Operations\{OperationsConfig,OperationsRuntime,SafeDiagnostics,AckHandoff,LaunchGate};

/** Separate, explicitly enabled small-group endpoint. No registration or periodic tick on HTTP. */
final class OperationsEndpoint
{
    private OperationsRuntime $runtime;
    public function __construct(private string $publicRoot,private PostResponseDriver $driver,?OperationsRuntime $runtime=null){$this->runtime=$runtime??new OperationsRuntime();}
    public function prepare(array $server,string $body,?string $configPath):EndpointDelivery
    {
        $started=hrtime(true);$mask=umask(0077);$diagnostics=null;
        $entryMarker=$GLOBALS['__radio_stamp_public_entry_marker_v1']??null;
        if(!$entryMarker instanceof \Closure){$entryMarker=null;}
        else{try{$entryMarker('attach');}catch(\Throwable){$entryMarker=null;}}
        try{
            if($configPath===null){throw new \RuntimeException('operations_unavailable');}
            $launch=LaunchGate::read($configPath,$this->publicRoot);
            if($launch!==null&&!$launch->permits($this->runtime->clock())){return new EndpointDelivery(new Response(503,['error'=>'operations_not_started']),null,null,$entryMarker);}
            $config=OperationsConfig::load($configPath,$this->publicRoot);
            if($launch!==null&&!$launch->matches($config)){throw new \RuntimeException('launch_gate_mismatch');}
            $diagnostics=SafeDiagnostics::fromConfig($config,null,$started,true);
            $diagnostics->record('http_prepare',['ok'=>true,'code'=>'config_ready']);
            $preparedQueue=null;$preparedHandoff=null;$runtime=$this->runtime;
            $queue=$this->driver->available()?static function()use($config,$runtime,$diagnostics,&$preparedQueue,&$preparedHandoff):InteractionQueue{
                if(method_exists($runtime,'prepareIngress')){
                    $ingress=$runtime->prepareIngress($config);
                    $preparedQueue=$ingress['queue'];$preparedHandoff=$ingress['handoff'];
                }else{
                    // A Runtime already loaded from the previous release keeps
                    // its established read-only pre-ACK construction contract.
                    $context=$runtime->build($config,static fn():bool=>true,null,$diagnostics,false);
                    try{$preparedQueue=$context->queue;$preparedHandoff=$context->handoff;}
                    finally{$context->roster->close();}
                }
                if(!$preparedHandoff instanceof AckHandoff){throw new \RuntimeException('ack_handoff_unavailable');}
                return $preparedQueue;
            }:null;
            $headers=['x-signature-ed25519'=>$server['HTTP_X_SIGNATURE_ED25519']??'','x-signature-timestamp'=>$server['HTTP_X_SIGNATURE_TIMESTAMP']??''];
            $controller=new InteractionController(OperationsConfig::controllerConfig($config),$queue,$this->runtime->clock(),$this->driver->available(),\RadioStamp\Operations\ParticipantScope::allowlist($config),'operations',$diagnostics);
            $response=$controller->handle($server['REQUEST_METHOD']??'',$headers,$body);
            $after=null;
            if($response->status===200&&$response->interactionId!==null){
                if(!$preparedQueue instanceof InteractionQueue||!$preparedHandoff instanceof AckHandoff){throw new \RuntimeException('ack_handoff_unavailable');}
                $ticket=$preparedHandoff->prepare($preparedQueue,$response->interactionId);
                $after=fn():array=>$this->runtime->acknowledgeAndRun($config,$response->interactionId,$preparedHandoff,$ticket,$diagnostics);
            }
            return new EndpointDelivery($response,$after,$diagnostics,$entryMarker);
        }catch(\Throwable $error){$diagnostics?->exception('http_prepare',$error);return new EndpointDelivery(new Response(503,['error'=>'operations_unavailable']),null,$diagnostics,$entryMarker);}
        finally{umask($mask);}
    }
}
