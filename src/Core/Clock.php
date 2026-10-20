<?php
declare(strict_types=1);
namespace RadioStamp\Core;

interface Clock
{
    public function now(): \DateTimeImmutable;
}
