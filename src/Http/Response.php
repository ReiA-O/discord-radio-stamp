<?php
declare(strict_types=1);
namespace RadioStamp\Http;

final class Response
{
    public function __construct(public readonly int $status, public readonly array $body, public readonly ?string $interactionId = null)
    {
        \RadioStamp\Discord\OutboundMentionGuard::assertJson(json_encode($body,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR));
    }

    public function emit(): void
    {
        $json=json_encode($this->body,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);
        \RadioStamp\Discord\OutboundMentionGuard::assertJson($json);
        http_response_code($this->status);
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store');
        header('X-Content-Type-Options: nosniff');
        echo $json;
    }
}
