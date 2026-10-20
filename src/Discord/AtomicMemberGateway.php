<?php
declare(strict_types=1);
namespace RadioStamp\Discord;

/**
 * The transport must compare source+generation and mutate as one indivisible operation.
 * A REST GET followed by PATCH does not implement this contract. Only the local mock
 * currently implements it. RadioService also independently refuses all live gateways.
 */
interface AtomicMemberGateway extends Gateway
{
    public function supportsConditionalMemberActions(): bool;
    public function moveMemberIfUnchanged(string $guildId, string $userId, string $destinationId, MemberActionFence $fence, string $key): bool;
    public function disconnectMemberIfUnchanged(string $guildId, string $userId, MemberActionFence $fence, string $key): bool;
}
