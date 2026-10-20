<?php
declare(strict_types=1);
namespace RadioStamp\Discord;

/** Optional cooperative stop support; existing GatewayTransport implementations remain valid. */
interface GuardedGatewayTransport extends GatewayTransport
{
    public function setGuard(\Closure $guard):void;
}
