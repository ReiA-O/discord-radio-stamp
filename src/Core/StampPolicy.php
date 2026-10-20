<?php
declare(strict_types=1);
namespace RadioStamp\Core;

/** Stable logical event identities; changing an event's clock time never changes its component. */
final class StampPolicy
{
    public const SLOT_STAMPS = ['midnight'=>'IC','dawn'=>'コンデンサ','morning'=>'LED','midday'=>'プラグ','night'=>'電池'];
    public const LEGACY_STAMPS = ['IC','コンデンサ','LED'];
    public const ALL_STAMPS = ['IC','コンデンサ','LED','プラグ','電池'];
    public const DEFAULT_TIMES = Schedule::DEFAULT_SLOT_TIMES;
    public static function stampForSlot(string $slotId): string { return self::SLOT_STAMPS[$slotId]??throw new \InvalidArgumentException('schedule_slot_invalid'); }
    public static function validStamp(mixed $stamp): bool { return is_string($stamp)&&in_array($stamp,self::ALL_STAMPS,true); }
    public static function defaultSlotForTime(string $time): ?string { $slot=array_search($time,self::DEFAULT_TIMES,true);return is_string($slot)?$slot:null; }
}
