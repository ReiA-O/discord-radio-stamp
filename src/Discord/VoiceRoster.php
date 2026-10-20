<?php
declare(strict_types=1);
namespace RadioStamp\Discord;

final readonly class VoiceRoster
{
    public function __construct(public bool $known, public array $memberIds, public int $observedAt, public int $generation = 0) {}
}
