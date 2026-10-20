<?php
declare(strict_types=1);
namespace RadioStamp\Discord;

/** A narrow marker for owner-only REST validation; never an atomic voice gateway. */
interface AdminValidationGateway extends Gateway
{
    public function permitsValidationUser(string $guildId, string $userId): bool;
}
