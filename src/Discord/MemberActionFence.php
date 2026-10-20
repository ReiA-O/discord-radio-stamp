<?php
declare(strict_types=1);
namespace RadioStamp\Discord;

/** A precondition, never a claim that Discord REST supports compare-and-swap. */
final readonly class MemberActionFence
{
    public function __construct(
        public string $guildId,
        public string $userId,
        public string $sourceChannelId,
        public int $generation,
        public int $observedAt,
    ) {}
}
