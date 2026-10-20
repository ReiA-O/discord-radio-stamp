<?php
declare(strict_types=1);
namespace RadioStamp\Http;

/** Ordinary flush() is deliberately not treated as a completed HTTP response. */
final class FastCgiPostResponseDriver implements PostResponseDriver
{
    public function available(): bool { return function_exists('fastcgi_finish_request'); }
    public function finish(): bool { return $this->available() && fastcgi_finish_request() === true; }
}
