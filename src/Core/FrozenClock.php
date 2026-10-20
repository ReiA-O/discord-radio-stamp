<?php
declare(strict_types=1);
namespace RadioStamp\Core;

final class FrozenClock implements Clock
{
    private \DateTimeImmutable $instant;

    public function __construct(\DateTimeImmutable|string $instant)
    {
        $this->set($instant);
    }

    public function now(): \DateTimeImmutable { return $this->instant; }

    public function set(\DateTimeImmutable|string $instant): void
    {
        $this->instant = is_string($instant)
            ? new \DateTimeImmutable($instant, new \DateTimeZone('Asia/Tokyo')) : $instant;
    }

    public function advance(int $seconds): void
    {
        $this->instant = $this->instant->modify(($seconds >= 0 ? '+' : '') . $seconds . ' seconds');
    }
}
