<?php
declare(strict_types=1);
namespace RadioStamp\Http;

use RadioStamp\Core\Clock;
use RadioStamp\Discord\ButtonBuilder;

/** Owner-only controls derived from the clock, never from a requested session. */
final class ValidationControls
{
    private readonly \RadioStamp\Core\Schedule $schedule;
    public function __construct(private readonly Clock $clock,array $config=[]) { $this->schedule=\RadioStamp\Core\Schedule::fromConfig($config); }

    public function forNow(): array
    {
        $now = $this->clock->now()->setTimezone(new \DateTimeZone('Asia/Tokyo'));
        $id=$this->schedule->initialSessionAt($now->getTimestamp());
        if ($id!==null) { return ButtonBuilder::sessionControls($id); }
        return [['type' => 1, 'components' => [['type' => 2, 'style' => 2, 'label' => 'カード表示', 'custom_id' => 'radio:card:' . $now->format('Y-m')]]]];
    }
}
