<?php
declare(strict_types=1);
namespace RadioStamp\Discord;

/** Local participant-flow definition; deliberately absent from the live validation registrar. */
final class ParticipantCommand
{
    public const NAME = 'radio-attend';

    public static function definition(): array
    {
        return ['type'=>1, 'name'=>self::NAME, 'description'=>'体操後の共有参加受付を開始（10分間）', 'nsfw'=>true, 'options'=>[]];
    }
}
