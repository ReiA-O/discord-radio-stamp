<?php
declare(strict_types=1);
namespace RadioStamp\Http;

/** Implementations must edit only the matching deferred interaction response. */
interface FollowupSink
{
    public function send(string $interactionId, string $token, array $result, ?string $png): bool;
}
