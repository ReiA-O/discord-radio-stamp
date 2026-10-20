<?php
declare(strict_types=1);
namespace RadioStamp\Http;

/** A deterministic boundary record. It does not contact Discord or retain tokens. */
final class MockFollowupSink implements FollowupSink
{
    public array $records = [];
    public bool $succeed = true;

    public function send(string $interactionId, string $token, array $result, ?string $png): bool
    {
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
