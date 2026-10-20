<?php
declare(strict_types=1);
namespace RadioStamp\Discord;

final class ReceiptMessage
{
    public static function build(string $sessionId, int $closesAt): array
    {
        $controls = ButtonBuilder::sessionControls($sessionId);
        if ($closesAt < 1) { throw new \InvalidArgumentException('Invalid receipt deadline.'); }
        $closed = (new \DateTimeImmutable('@'.$closesAt))->setTimezone(new \DateTimeZone('Asia/Tokyo'));
        return [
            'content'=>"参加受付を開始しました！今から10分後に閉場します。参加した方はボタンを押してね\n閉場時刻：".$closed->format('Y-m-d H:i:s').' JST',
            'components'=>[['type'=>1,'components'=>array_slice($controls[0]['components'],1)]],
            'allowed_mentions'=>Gateway::ALLOWED_MENTIONS, 'tts'=>false,
        ];
    }
}
