<?php
declare(strict_types=1);
namespace RadioStamp\Validation\OneJoin;

use RadioStamp\Http\EndpointDelivery;
use RadioStamp\Http\PostResponseDriver;
use RadioStamp\Http\Response;

final class Delivery
{
    private bool $used = false;
    public function __construct(public readonly Response $response, private readonly ?Store $store = null, private readonly ?Runtime $runtime = null, private readonly float $started = 0) {}
    public function deliver(PostResponseDriver $driver, ?\Closure $emitter = null): bool
    {
        if ($this->used) { return false; }
        $this->used = true; $finished = false;
        $id = $this->response->interactionId;
        $callback = $id === null ? null : function () use ($id, &$finished): void {
            $finished = true;
            $elapsed = (hrtime(true) / 1e9 - $this->started) * 1000;
            $bucket = $elapsed < 100 ? 'lt_100ms' : ($elapsed < 500 ? 'lt_500ms' : ($elapsed < 2000 ? 'lt_2000ms' : 'ge_2000ms'));
            $this->store->ack($id, $bucket);
            $this->runtime->run($id);
        };
        $ok = (new EndpointDelivery($this->response, $callback))->deliver($driver, $emitter);
        if (!$ok && $id !== null) {
            try { $this->store->finish($id, 'failed', $finished ? 'runtime_failed' : 'response_finish_failed', false, false); } catch (\Throwable) {}
        }
        return $ok;
    }
}
