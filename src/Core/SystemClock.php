<?php
declare(strict_types=1);
namespace RadioStamp\Core;

final class SystemClock implements Clock
{
    public function now(): \DateTimeImmutable
    {
        return new \DateTimeImmutable('now', new \DateTimeZone('Asia/Tokyo'));
    }
}
