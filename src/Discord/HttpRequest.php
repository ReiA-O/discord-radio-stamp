<?php
declare(strict_types=1);
namespace RadioStamp\Discord;

/** Secrets are available only to the HTTP adapter, never through debug output. */
final class HttpRequest
{
    public function __construct(
        private readonly string $verb,
        #[\SensitiveParameter] private readonly string $address,
        #[\SensitiveParameter] private readonly array $requestHeaders,
        #[\SensitiveParameter] private readonly string $requestBody = '',
        public readonly int $timeoutMs = 5000,
        public readonly int $connectTimeoutMs = 1500,
        public readonly int $maxResponseBytes = 1048576,
        public readonly bool $asyncDnsRequired = false,
        private readonly ?RoleMutationScope $roleMutationScope = null,
        private readonly ?RoomMutationScope $roomMutationScope = null,
    ) {
        $parts = parse_url($address);
        // DELETE is limited to the one fixed VC message path; ordinary REST still
        // exposes no delete operation. The one-join guard also binds its own ID.
        $scopedRoleMutation = is_array($parts) && $roleMutationScope?->permits($verb,$parts['path'] ?? '') === true;
        $scopedRoomMutation = is_array($parts) && $roomMutationScope?->permits($verb,$parts['path'] ?? '') === true;
        $fixedCommandReadQuery = $verb === 'GET' && $address === 'https://discord.com/api/v10/applications/800000000000001003/guilds/800000000000001001/commands?with_localizations=true';
        $deleteAllowed = $verb !== 'DELETE' || $scopedRoleMutation || (is_array($parts) && preg_match('~\A/api/v10/channels/800000000000001007/messages/[1-9][0-9]{16,19}\z~D', $parts['path'] ?? '') === 1);
        if ((!in_array($verb, ['GET', 'PATCH', 'POST', 'DELETE'], true) && !$scopedRoleMutation && !$scopedRoomMutation) || !$deleteAllowed || !is_array($parts) ||
            ($parts['scheme'] ?? '') !== 'https' || ($parts['host'] ?? '') !== 'discord.com' ||
            isset($parts['port']) || isset($parts['user']) || isset($parts['pass']) ||
            (isset($parts['query']) && !$fixedCommandReadQuery) || isset($parts['fragment']) || !str_starts_with($parts['path'] ?? '', '/api/v10/') ||
            preg_match('/[\r\n\x00]/', $address) || $timeoutMs < 100 || $timeoutMs > 10000 ||
            $connectTimeoutMs < 100 || $connectTimeoutMs > $timeoutMs || $maxResponseBytes < 1024 || $maxResponseBytes > 2097152) {
            throw new \InvalidArgumentException('discord_request_invalid');
        }
        foreach ($requestHeaders as $header) {
            if (!is_string($header) || preg_match('/[\r\n\x00]/', $header)) { throw new \InvalidArgumentException('discord_request_invalid'); }
        }
    }
    public function method(): string { return $this->verb; }
    public function url(): string { return $this->address; }
    public function headers(): array { return $this->requestHeaders; }
    public function body(): string { return $this->requestBody; }
    public function __debugInfo(): array { return ['method' => $this->verb, 'destination' => 'discord_api', 'credentials' => '[redacted]']; }
}
