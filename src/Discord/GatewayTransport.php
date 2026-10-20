<?php
declare(strict_types=1);
namespace RadioStamp\Discord;
interface GatewayTransport
{
    public function connect():bool;
    public function send(#[\SensitiveParameter] array $payload):bool;
    public function receive(float $timeoutSeconds):?array;
    public function close():void;
}
