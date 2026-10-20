<?php
declare(strict_types=1);
namespace RadioStamp\Http;

final class Response
{
    public function __construct(public readonly int $status, public readonly array $body, public readonly ?string $interactionId = null) {}

    public function emit(): void
    {
        http_response_code($this->status);
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store');
        header('X-Content-Type-Options: nosniff');
        echo json_encode($this->body, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    }
}
