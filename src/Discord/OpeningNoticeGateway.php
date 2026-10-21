<?php
declare(strict_types=1);
namespace RadioStamp\Discord;

/** Dedicated fixed-content capability; ordinary messages keep all mentions disabled. */
interface OpeningNoticeGateway
{
    /** Read-only confirmation of the current fixed VC Connect overwrite, not a permission mutation. */
    public function openingRoomVerified():bool;
    public function sendOpeningNoticeResult(array $snapshot,string $key):RestResult;
}
