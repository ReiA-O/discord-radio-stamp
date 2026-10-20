<?php
declare(strict_types=1);
namespace RadioStamp\Discord;

/** Discord payload construction only; no message is sent by this builder. */
final class ButtonBuilder
{
    public static function sessionControls(string $sessionId): array
    {
        // Syntax only. Configured schedule membership is checked at ingress and in Core.
        if (!\RadioStamp\Core\Schedule::validSessionId($sessionId)) {
            throw new \InvalidArgumentException('Invalid session button identifier.');
        }
        return [[
            'type' => 1,
            'components' => [
                ['type' => 2, 'style' => 1, 'label' => '体操開始', 'custom_id' => 'radio:start:' . $sessionId],
                ['type' => 2, 'style' => 3, 'label' => '参加', 'custom_id' => 'radio:attend:' . $sessionId],
                ['type' => 2, 'style' => 2, 'label' => 'カード表示', 'custom_id' => 'radio:card:' . substr($sessionId, 0, 7)],
            ],
        ]];
    }
}
