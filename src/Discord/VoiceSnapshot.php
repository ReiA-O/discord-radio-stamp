<?php
declare(strict_types=1);
namespace RadioStamp\Discord;

/** Sanitized local projection. Transport health is not a proof of instantaneous server state. */
final readonly class VoiceSnapshot
{
    public function __construct(
        public bool $known,
        public string $guildId,
        public array $channelsByUser,
        public int $observedAt,
        public int $generation,
        public ?int $sequence,
        public ?int $lastTransportHealthAt,
        public string $reason,
    ) {}

    public function voiceState(string $userId): VoiceState
    {
        return new VoiceState($this->known, $this->known ? ($this->channelsByUser[$userId] ?? null) : null, $this->observedAt, $this->generation);
    }

    public function roster(string $channelId): VoiceRoster
    {
        $members = [];
        if ($this->known) { foreach ($this->channelsByUser as $userId => $channel) { if ($channel === $channelId) { $members[] = (string)$userId; } } }
        sort($members, SORT_STRING);
        return new VoiceRoster($this->known, $members, $this->observedAt, $this->generation);
    }
}
