<?php
declare(strict_types=1);
namespace RadioStamp\Http;

use RadioStamp\Discord\RestResult;

/** A sink that can report why its last send failed (rate limit wait, unknown webhook), without payloads or tokens. */
interface FollowupOutcome
{
    public function lastResult(): ?RestResult;
}
