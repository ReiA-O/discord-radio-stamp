<?php
declare(strict_types=1);
namespace RadioStamp\Discord;
interface ReceiptGateway extends Gateway { public function sendReceipt(string $sessionId,int $closesAt,string $key):bool; }
