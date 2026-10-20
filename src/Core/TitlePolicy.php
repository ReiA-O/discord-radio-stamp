<?php
declare(strict_types=1);
namespace RadioStamp\Core;

/** Approved count-based titles; never a participation-day or streak policy. */
final class TitlePolicy
{
    public const TITLES = [
        1 => '⚡ はじめの通電', 40 => '🔌 接続完了', 80 => '⚙️ 暖機運転',
        120 => '💻 起動完了', 160 => '🔋 安定稼働', 200 => '🔧 最適化中',
        240 => '📡 同期完了', 280 => '🚀 定格突破', 320 => '🌟 高効率運転',
        365 => '🏆 永年稼働',
    ];

    public static function forCount(int $count): array
    {
        $threshold = null; $title = null;
        foreach (self::TITLES as $at => $label) {
            if ($count >= $at) { $threshold = $at; $title = $label; }
        }
        return [$threshold, $title];
    }
}
