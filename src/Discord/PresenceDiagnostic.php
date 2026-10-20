<?php
declare(strict_types=1);
namespace RadioStamp\Discord;

/** Two scalars only: never retains a response, request, identity or error text. */
final readonly class PresenceDiagnostic
{
    private function __construct(public ?int $httpStatus, public string $classification) {}

    public static function fromResult(RestResult $result): self
    {
        $status = !$result->syntheticStatus && $result->status >= 100 && $result->status <= 599 ? $result->status : null;
        $allowed = ['ok', 'not_found', 'forbidden', 'unauthorized', 'response_invalid',
            'transport_timeout', 'transport_failed', 'response_too_large', 'transport_unavailable',
            'transport_async_dns_required', 'operation_deadline', 'rate_limited', 'rate_limit_invalid',
            'redirect_refused', 'discord_unavailable', 'discord_rejected', 'scope_rejected',
            'transport_disabled', 'operation_budget_exhausted', 'identity_mismatch'];
        $classification = in_array($result->code, $allowed, true) ? $result->code : 'unclassified';
        if ($result->syntheticStatus) {
            $classification = match ($result->code) { 'rate_limited' => 'local_rate_limited', 'unauthorized' => 'local_auth_stop', default => 'unclassified' };
        }
        if ($classification === 'ok' && (!$result->ok || $status === null || $status < 200 || $status >= 300)) { $classification = 'unclassified'; }
        return new self($status, $classification);
    }

    public function toArray(): array { return ['http_status' => $this->httpStatus, 'classification' => $this->classification]; }
}
