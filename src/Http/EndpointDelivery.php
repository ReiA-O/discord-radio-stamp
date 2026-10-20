<?php
declare(strict_types=1);
namespace RadioStamp\Http;
use RadioStamp\Operations\SafeDiagnostics;

final class EndpointDelivery
{
    private bool $used = false;
    public function __construct(public readonly Response $response, private readonly ?\Closure $afterResponse = null,private readonly ?SafeDiagnostics $diagnostics=null) {}

    /** The injected emitter is for offline tests, never selected by HTTP input. */
    public function deliver(PostResponseDriver $driver, ?\Closure $emitter = null): bool
    {
        if ($this->used) { return false; }
        $this->used = true;
        $stage='ack_emit';
        try {
            if ($emitter === null) { $this->response->emit(); } else { $emitter($this->response); }
            $this->diagnostics?->record('ack_emit',['ok'=>$this->response->status===200,'status'=>$this->response->status,'code'=>'emitted']);
            if ($this->afterResponse === null) { return true; }
            // A false finish result must never run network or receipt work.
            $stage='ack_finish';$finished=$driver->available()&&$driver->finish();
            $this->diagnostics?->record('ack_finish',['ok'=>$finished,'finished'=>$finished,'code'=>$finished?'finished':'unavailable']);
            if (!$finished) { return false; }
            $stage='after_response';($this->afterResponse)();
            $this->diagnostics?->record('after_response',['ok'=>true,'code'=>'done']);return true;
        }
        catch (\Throwable $error) {$this->diagnostics?->exception($stage,$error);return false;}
    }
}
