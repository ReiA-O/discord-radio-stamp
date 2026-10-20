<?php
declare(strict_types=1);
namespace RadioStamp\Discord;
/** Explicitly non-atomic; never substitutes for AtomicMemberGateway without the separate risk choice. */
interface BestEffortMemberGateway extends Gateway
{
    public function supportsBestEffortMemberActions():bool;
    public function moveMemberGuarded(string $guild,string $user,string $destination,MemberActionFence $fence,string $key):bool;
    public function disconnectMemberGuarded(string $guild,string $user,MemberActionFence $fence,string $key):bool;
}
