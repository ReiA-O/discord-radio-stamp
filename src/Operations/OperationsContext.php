<?php
declare(strict_types=1);
namespace RadioStamp\Operations;
use RadioStamp\Core\{Database,RadioService,Clock};
use RadioStamp\Discord\{ServerOperationsClient,GatewayRosterSession};
use RadioStamp\Http\{InteractionQueue,InteractionWorker};
final readonly class OperationsContext
{
    public function __construct(public Database $db,public Clock $clock,public ServerOperationsClient $client,public RadioService $service,
        public GatewayRosterSession $roster,public InteractionQueue $queue,public InteractionWorker $worker,public string $closurePolicy='manual',public ?SafeDiagnostics $diagnostics=null,public ?AckHandoff $handoff=null){}
    public function __debugInfo():array{return ['scope'=>'small_group_operations','credentials'=>'[redacted]'];}
}
