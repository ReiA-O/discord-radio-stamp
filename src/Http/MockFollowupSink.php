<?php
declare(strict_types=1);
namespace RadioStamp\Http;

use RadioStamp\Discord\RestResult;

/** A deterministic boundary record. It does not contact Discord or retain tokens. */
final class MockFollowupSink implements FollowupSink, FollowupOutcome
{
    public array $records = [];
    public bool $succeed = true;
    /** Simulated reason for a failed send (e.g. rate_limited with retryAfter, not_found); null = unknown. */
    public ?RestResult $failure = null;
    public int $sends = 0;

    public function lastResult(): ?RestResult { return $this->succeed ? null : $this->failure; }

    public function send(string $interactionId, string $token, array $result, ?string $png): bool
    {
        ++$this->sends;
        if (!$this->succeed) { return false; }
        if (!isset($this->records[$interactionId])) {
            $this->records[$interactionId] = [
                'transport' => 'mock_only', 'interaction_id' => $interactionId,
                'ephemeral' => true, 'result' => $result,
                'png_bytes' => $png === null ? 0 : strlen($png),
                'png_sha256' => $png === null ? null : hash('sha256', $png),
                'png_signature_valid' => $png === null ? null : str_starts_with($png, "\x89PNG\r\n\x1a\n"),
            ];
        }
        return true;
    }
}
