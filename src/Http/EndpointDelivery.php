<?php
declare(strict_types=1);
namespace RadioStamp\Http;
use RadioStamp\Operations\SafeDiagnostics;

final class EndpointDelivery
{
    private bool $used = false;
    public function __construct(public readonly Response $response, private readonly ?\Closure $afterResponse = null,private readonly ?SafeDiagnostics $diagnostics=null,private readonly ?\Closure $entryMarker=null) {}

    /** The injected emitter is for offline tests, never selected by HTTP input. */
    public function deliver(PostResponseDriver $driver, ?\Closure $emitter = null): bool
    {
        if ($this->used) { return false; }
        $this->used = true;
        $stage='ack_emit';
        try {
            if ($emitter === null) { $this->response->emit(); } else { $emitter($this->response); }
            $this->diagnostics?->record('ack_emit',['ok'=>$this->response->status===200,'status'=>$this->response->status,'code'=>'emitted']);
        }
        catch (\Throwable $error) {$this->diagnostics?->exception($stage,$error);$this->diagnostics?->discard();return false;}
        // Plain responses have no work after emission and need no finish capability.
        if($this->afterResponse===null&&$this->diagnostics===null&&$this->entryMarker===null){return true;}
        $stage='ack_finish';
        try {
            try{if($this->entryMarker!==null){($this->entryMarker)('finish_attempted');}}catch(\Throwable){}
            if(!$driver->available()){$this->diagnostics?->discard();return $this->afterResponse===null;}
            // A false finish result must never run network or receipt work.
            $finished=$driver->finish();
            $this->diagnostics?->record('ack_finish',['ok'=>$finished,'finished'=>$finished,'code'=>$finished?'finished':'unavailable']);
            if(!$finished){$this->diagnostics?->discard();return false;}
            try{if($this->entryMarker!==null){($this->entryMarker)('response_finished');}}catch(\Throwable){}
        }
        catch(\Throwable $error){$this->diagnostics?->exception($stage,$error);$this->diagnostics?->discard();return false;}
        $ok=true;
        if($this->afterResponse!==null){
            $stage='after_response';
            try{($this->afterResponse)();$this->diagnostics?->record('after_response',['ok'=>true,'code'=>'done']);}
            catch(\Throwable $error){$this->diagnostics?->exception($stage,$error);$ok=false;}
        }
        // Both writers are best-effort and run only after the client response is closed.
        // Durable ACK handoff/callback work runs first so a slow writer cannot widen its crash window.
        try{if($this->entryMarker!==null){($this->entryMarker)('finished');}}catch(\Throwable){}
        $this->diagnostics?->flush();
        return $ok;
    }
}
