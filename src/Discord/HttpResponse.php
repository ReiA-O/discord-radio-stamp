<?php
declare(strict_types=1);
namespace RadioStamp\Discord;

final readonly class HttpResponse
{
    public function __construct(public int $status, public array $headers = [], public string $body = '', public ?string $error = null) {}
    public function __debugInfo(): array { return ['status' => $this->status, 'transport_failed' => $this->error !== null]; }
}
