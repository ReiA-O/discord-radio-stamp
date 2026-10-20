<?php
declare(strict_types=1);
namespace RadioStamp\Http;

use RadioStamp\Core\Database;
use RadioStamp\Security\TokenCipher;
use RadioStamp\Operations\{OperationsConfig,OperationsRuntime,SafeDiagnostics,AckHandoff};

/** Separate, explicitly enabled small-group endpoint. No registration or periodic tick on HTTP. */
final class OperationsEndpoint
{
    private OperationsRuntime $runtime;
    public function __construct(private string $publicRoot,private PostResponseDriver $driver,?OperationsRuntime $runtime=null){$this->runtime=$runtime??new OperationsRuntime();}
    public function prepare(array $server,string $body,?string $configPath):EndpointDelivery
    {
        $started=hrtime(true);$mask=umask(0077);$diagnostics=null;
        try{
            if($configPath===null){throw new \RuntimeException('operations_unavailable');}
            $config=OperationsConfig::load($configPath,$this->publicRoot);
            $diagnostics=SafeDiagnostics::fromConfig($config,null,$started);
            $diagnostics->record('http_prepare',['ok'=>true,'code'=>'config_ready']);
            $preparedQueue=null;
            $queue=$this->driver->available()?static function()use($config,&$preparedQueue):InteractionQueue{
                $db=new Database($config['private_db_path'],200);$preparedQueue=new InteractionQueue($db->pdo(),new TokenCipher($config['token_encryption_key_hex']));
                $preparedQueue->initializeAcknowledgements();return $preparedQueue;
            }:null;
            $headers=['x-signature-ed25519'=>$server['HTTP_X_SIGNATURE_ED25519']??'','x-signature-timestamp'=>$server['HTTP_X_SIGNATURE_TIMESTAMP']??''];
            $controller=new InteractionController(OperationsConfig::controllerConfig($config),$queue,$this->runtime->clock(),$this->driver->available(),\RadioStamp\Operations\ParticipantScope::allowlist($config),'operations',$diagnostics);
            $response=$controller->handle($server['REQUEST_METHOD']??'',$headers,$body);
            $after=null;
            if($response->status===200&&$response->interactionId!==null){
                if(!$preparedQueue instanceof InteractionQueue){throw new \RuntimeException('ack_handoff_unavailable');}
                $handoff=new AckHandoff($config);$ticket=$handoff->prepare($preparedQueue,$response->interactionId);
                $after=fn():array=>$this->runtime->acknowledgeAndRun($config,$response->interactionId,$handoff,$ticket,$diagnostics);
            }
            return new EndpointDelivery($response,$after,$diagnostics);
        }catch(\Throwable $error){$diagnostics?->exception('http_prepare',$error);return new EndpointDelivery(new Response(503,['error'=>'operations_unavailable']),null,$diagnostics);}
        finally{umask($mask);}
    }
}
