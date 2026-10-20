<?php
declare(strict_types=1);

namespace RadioStamp\Cards;

use DateTimeImmutable;
use DateTimeZone;
use GdImage;
use InvalidArgumentException;
use RuntimeException;
use Throwable;

/** Shared monthly stamp cards; memorial artwork remains an explicitly temporary display. */
final class CardRenderer
{
    // A user can have at most one attendance per minute-precision session ID.
    // Keep this history boundary independent of the currently configured schedule.
    private const MAX_SESSIONS_PER_DAY = 24 * 60;
    private const MONTHLY_WIDTH = 1200;
    private const MONTHLY_OVERVIEW_HEIGHT = 1250;
    private const MAX_CANVAS_HEIGHT = 10000;
    private const MAX_CANVAS_PIXELS = 12_000_000;
    private const FONTS = [
        '/usr/share/fonts/ipa-gothic/ipag.ttf',
        '/usr/share/fonts/ipa-pgothic/ipagp.ttf',
        '/usr/share/fonts/ja/TrueType/kochi-gothic-subst.ttf',
        '/usr/share/fonts/vlgothic/VL-Gothic-Regular.ttf',
        '/usr/share/fonts/opentype/noto/NotoSansCJK-Regular.ttc',
        '/usr/share/fonts/ipaexg/ipaexg.ttf',
        'C:/Windows/Fonts/BIZ-UDGothicR.ttc',
        'C:/Windows/Fonts/msgothic.ttc',
    ];
    private string $font;
    private string $missingFingerprint;
    private array $glyphCache = [];
    private array $colors = [];
    private readonly MemorialArtwork $memorialArtwork;

    public function __construct(array $fontCandidates, ?string $trustedMemorialArtworkRoot = null)
    {
        $this->memorialArtwork = new MemorialArtwork($trustedMemorialArtworkRoot);
        if (!extension_loaded('gd') || !function_exists('imagettftext')) {
            throw new RuntimeException('Card rendering is unavailable.');
        }
        foreach ($fontCandidates as $candidate) {
            if (!is_string($candidate) || !$this->allowedFont($candidate)) {
                continue;
            }
            $candidate = str_replace('\\', '/', $candidate);
            if (!is_readable($candidate)) {
                continue;
            }
            try {
                $missing = $this->glyphFingerprint($candidate, "\u{10FFFF}");
                $samples = [];
                foreach (['日', '本', '語', 'A', '0'] as $character) {
                    $samples[] = $this->glyphFingerprint($candidate, $character);
                }
                // A readable TTC can still select an unusable face on some GD builds.
                if (in_array($missing, $samples, true) || count(array_unique($samples)) !== 5) {
                    continue;
                }
                $this->font = $candidate;
                $this->missingFingerprint = $missing;
                return;
            } catch (Throwable) {
                // Never expose a path or FreeType error to the image consumer.
            }
        }
        throw new RuntimeException('A Japanese card font is unavailable.');
    }

    public function monthly(array $data): string
    {
        $card = $this->validate($data);
        $layout = $this->monthlyLayout($card['days']);
        $image = $this->canvas(self::MONTHLY_WIDTH, $layout['height']);
        try {
            imageantialias($image, true);
            $this->text($image, 'ラジオ体操 スタンプカード', 30, 48, 65, 'ink');
            $this->fitText($image, 'JST / 開催ごとに1回', 15, 930, 65, 222, 'muted');
            $this->fitText($image, $card['name'] . ' さん', 29, 48, 122, 1104, 'ink');
            $this->text($image, $card['date']->format('Y') . '年 ' . (int)$card['date']->format('n') . '月', 28, 48, 181, 'ink');
            $this->fitText($image, '現在のロール: ' . $card['title'], 18, 420, 179, 732, 'ink');
            foreach ([
                ['この月の開催分', $card['month_count'], '回'], ['今月の参加日数', $card['month_days'], '日'],
                ['累計参加', $card['total_count'], '回'], ['累計参加日数', $card['total_days'], '日'],
            ] as $i => [$label, $value, $unit]) {
                $x = 48 + 276 * $i;
                $this->text($image, $label, 16, $x, 222, 'muted');
                $this->fitText($image, $value . ' ' . $unit, 26, $x, 264, 252, 'ink');
            }
            $this->monthlyCalendar($image, $card);
            $this->text($image, '新規記録の通常枠（JST）', 14, 48, 1090, 'muted');
            foreach (\RadioStamp\Core\Schedule::DEFAULT_SPEC['daily_times'] as $i => $time) {
                $slot = \RadioStamp\Core\StampPolicy::defaultSlotForTime($time);
                $stamp = \RadioStamp\Core\StampPolicy::stampForSlot($slot);
                $x = 76 + $i * 220;
                $this->component($image, $stamp, $x, 1120, 0.28);
                $this->text($image, $stamp, 16, $x + 27, 1127, 'ink');
                $this->text($image, $time, 14, $x - 22, 1160, 'muted');
            }
            $this->text($image, '過去は保存時の絵 / 日数はJST同日1日', 14, 48, 1190, 'muted');
            $this->text($image, $layout['groups'] === []
                ? '第1・第2で1セット / 欠席によるリセットなし'
                : 'カレンダーの +n は下部の全件明細へ（先頭5件も再掲）', 14, 48, 1221, 'muted');
            if ($layout['groups'] !== []) { $this->monthlyDetails($image, $layout); }
            return $this->encode($image);
        } finally { imagedestroy($image); }
    }

    /** Compute bounded geometry before allocating a canvas. Valid old history is never discarded. */
    private function monthlyLayout(array $days): array
    {
        $detailDays = array_filter($days, static fn(array $stamps): bool => count($stamps) > 5);
        if ($detailDays === []) { return ['height' => self::MONTHLY_OVERVIEW_HEIGHT, 'groups' => []]; }
        ksort($detailDays);
        $count = array_sum(array_map('count', $detailDays));
        // Dense mode keeps every record within the existing 128 MiB runtime,
        // including GD's temporary PNG encoding memory (31 x 1440 = 9481 px high).
        [$pitch, $scale, $density] = $count <= 256 ? [52, 0.30, 'large']
            : ($count <= 2048 ? [32, 0.18, 'medium'] : [11, 0.06, 'dense']);
        $columns = intdiv(1104, $pitch);
        $cursor = 1370;
        $groups = [];
        foreach ($detailDays as $date => $stamps) {
            $rows = intdiv(count($stamps) + $columns - 1, $columns);
            $groups[] = ['date' => $date, 'stamps' => $stamps, 'top' => $cursor, 'rows' => $rows];
            $cursor += 68 + $rows * $pitch + 28;
        }
        $height = $cursor + 20;
        if ($height > self::MAX_CANVAS_HEIGHT || self::MONTHLY_WIDTH * $height > self::MAX_CANVAS_PIXELS) {
            throw new RuntimeException('Card dimensions are unavailable.');
        }
        return ['height' => $height, 'groups' => $groups, 'pitch' => $pitch,
            'scale' => $scale, 'columns' => $columns, 'density' => $density];
    }

    private function monthlyCalendar(GdImage $image, array $card): void
    {
        $left = 48; $top = 294; $cellWidth = 1104 / 7; $rowHeight = 120;
        foreach (['日', '月', '火', '水', '木', '金', '土'] as $i => $weekday) {
            $x = (int)round($left + $i * $cellWidth);
            imagefilledrectangle($image, $x, $top, (int)round($x + $cellWidth), $top + 48,
                $this->colors[$i === 0 ? 'sun_bg' : ($i === 6 ? 'sat_bg' : 'soft')]);
            $this->centerText($image, $weekday, 17, (int)($x + $cellWidth / 2), $top + 31,
                $i === 0 ? 'red' : ($i === 6 ? 'blue' : 'muted'));
        }
        $gridTop = $top + 48;
        for ($row = 0; $row <= 6; ++$row) {
            imageline($image, $left, $gridTop + $row * $rowHeight, 1152, $gridTop + $row * $rowHeight, $this->colors['line']);
        }
        for ($column = 0; $column <= 7; ++$column) {
            $x = (int)round($left + $column * $cellWidth);
            imageline($image, $x, $gridTop, $x, $gridTop + 6 * $rowHeight, $this->colors['line']);
        }
        $offset = (int)$card['date']->format('w');
        for ($day = 1; $day <= (int)$card['date']->format('t'); ++$day) {
            $position = $offset + $day - 1;
            $column = $position % 7; $row = intdiv($position, 7);
            $x = (int)round($left + $column * $cellWidth); $y = $gridTop + $row * $rowHeight;
            $this->text($image, (string)$day, 18, $x + 13, $y + 29,
                $column === 0 ? 'red' : ($column === 6 ? 'blue' : 'ink'));
            $stamps = $card['days'][$card['month'] . '-' . sprintf('%02d', $day)] ?? [];
            if (count($stamps) > 5) {
                // Keep the extra-count label in the date row, separate from all five stamps.
                $this->fitText($image, '+' . (count($stamps) - 5) . ' 明細', 11, $x + 50, $y + 27, 96, 'muted');
            }
            $positions = [[30, 56], [79, 56], [128, 56], [54, 98], [104, 98]];
            foreach (array_slice($stamps, 0, 5) as $i => $stamp) {
                [$dx, $dy] = $positions[$i];
                $this->component($image, $stamp, $x + $dx, $y + $dy, 0.22);
            }
        }
    }

    private function monthlyDetails(GdImage $image, array $layout): void
    {
        $this->text($image, '日別の全件明細', 26, 48, 1314, 'ink');
        $this->fitText($image, $layout['density'] === 'dense' ? '高密度表示 / 拡大して確認'
            : ($layout['density'] === 'medium' ? '縮小表示 / 拡大して確認' : '個別スタンプを全件表示'), 16, 660, 1312, 492, 'muted');
        $this->text($image, '先頭5件も含む全件 / 順序: 左→右、上→下', 16, 48, 1350, 'muted');
        foreach ($layout['groups'] as $group) {
            $stamps = $group['stamps']; $top = $group['top'];
            $this->fitText($image, $group['date'] . ' / 全' . count($stamps) . '件（先頭5件を含む）', 18, 48, $top + 26, 1104, 'ink');
            $counts = array_count_values($stamps);
            $this->text($image, 'IC ' . ($counts['IC'] ?? 0) . ' / コンデンサ ' . ($counts['コンデンサ'] ?? 0)
                . ' / LED ' . ($counts['LED'] ?? 0) . ' / プラグ ' . ($counts['プラグ'] ?? 0)
                . ' / 電池 ' . ($counts['電池'] ?? 0), 14, 48, $top + 54, 'muted');
            foreach ($stamps as $i => $stamp) {
                $x = 48 + (int)round($layout['pitch'] * (($i % $layout['columns']) + 0.5));
                $y = $top + 68 + (int)round($layout['pitch'] * (intdiv($i, $layout['columns']) + 0.5));
                $this->component($image, $stamp, $x, $y, $layout['scale']);
            }
        }
    }

    public function memorial(array $data, int $milestone): string
    {
        $card = $this->validate($data);
        if (($data['milestone_basis'] ?? null) !== 'attendance_days_jst'
            || !in_array($milestone, \RadioStamp\Core\ParticipationDays::MILESTONES, true)
            || !in_array($milestone, $card['awards'], true) || $card['total_days'] < $milestone) {
            throw new InvalidArgumentException('This day milestone is not reached.');
        }
        $artwork = $this->memorialArtwork->load($milestone);
        if ($artwork !== null) { return $artwork; }
        $image = $this->canvas(1200, 800);
        try {
            $this->text($image, 'ラジオ体操 仮カード', 32, 60, 100, 'ink');
            $this->text($image, 'デザイン調整中', 22, 60, 155, 'muted');
            $this->fitText($image, $card['name'] . ' さん', 30, 60, 255, 1080, 'ink');
            $this->text($image, '累計参加日数  ' . $milestone . ' 日の節目', 34, 60, 380, 'ink');
            $this->text($image, '現在の参加日数  ' . $card['total_days'] . ' 日', 24, 60, 480, 'ink');
            $this->text($image, '特典内容は相談中です', 22, 60, 620, 'muted');
            return $this->encode($image);
        } finally { imagedestroy($image); }
    }

    private function validate(array $data): array
    {
        $month = $data['month'] ?? null;
        if (!is_string($month) || !preg_match('/\A([0-9]{4})-(?:0[1-9]|1[0-2])\z/D', $month, $parts)
            || (int)$parts[1] < 1) { throw new InvalidArgumentException('Invalid card month.'); }
        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $month . '-01', new DateTimeZone('Asia/Tokyo'));
        if ($date === false || $date->format('Y-m') !== $month) { throw new InvalidArgumentException('Invalid card month.'); }
        $daysInMonth = (int)$date->format('t');
        $monthly = $data['month_count'] ?? null; $total = $data['total_count'] ?? null;
        $monthDays = $data['month_days'] ?? null; $totalDays = $data['total_days'] ?? null;
        if (!is_int($monthly) || !is_int($total) || $monthly < 0 || $monthly > $daysInMonth * self::MAX_SESSIONS_PER_DAY || $total < $monthly
            || !is_int($monthDays) || !is_int($totalDays) || $monthDays < 0 || $monthDays > $daysInMonth
            || $totalDays < $monthDays || $totalDays > $total) { throw new InvalidArgumentException('Invalid card counts.'); }
        $days = $data['days'] ?? null; $awards = $data['awards'] ?? null;
        if (!is_array($days) || count($days) > $daysInMonth || !is_array($awards) || count($awards) > 7
            || !array_is_list($awards) || count(array_unique($awards, SORT_REGULAR)) !== count($awards)) {
            throw new InvalidArgumentException('Invalid card records.');
        }
        $count = 0;
        foreach ($days as $key => $stamps) {
            if (!is_string($key) || !preg_match('/\A' . preg_quote($month, '/') . '-([0-9]{2})\z/D', $key, $matches)
                || (int)$matches[1] < 1 || (int)$matches[1] > $daysInMonth
                || !is_array($stamps) || count($stamps) > self::MAX_SESSIONS_PER_DAY || !array_is_list($stamps)) {
                throw new InvalidArgumentException('Invalid card records.');
            }
            foreach ($stamps as $stamp) {
                if (!\RadioStamp\Core\StampPolicy::validStamp($stamp)) { throw new InvalidArgumentException('Invalid card stamp.'); }
                ++$count;
            }
        }
        if ($count !== $monthly) { throw new InvalidArgumentException('Invalid card records.'); }
        foreach ($awards as $award) {
            if (!in_array($award, \RadioStamp\Core\ParticipationDays::MILESTONES, true) || $award > $totalDays) {
                throw new InvalidArgumentException('Invalid day milestones.');
            }
        }
        $name = $this->safeText($data['display_name'] ?? '', 120);
        [, $currentTitle] = \RadioStamp\Core\TitlePolicy::forCount($total);
        $title = $currentTitle === null ? '未取得' : (explode(' ', $currentTitle, 2)[1] ?? $currentTitle);
        return ['name' => $name === '' ? 'メンバー' : $name, 'month' => $month, 'date' => $date,
            'month_count' => $monthly, 'total_count' => $total, 'month_days' => $monthDays,
            'total_days' => $totalDays, 'awards' => $awards, 'days' => $days, 'title' => $this->safeText($title, 40)];
    }
    private function allowedFont(string $candidate): bool
    {
        $candidate = str_replace('\\', '/', $candidate);
        foreach (self::FONTS as $allowed) {
            if (str_starts_with($allowed, 'C:') ? strcasecmp($candidate, $allowed) === 0 : $candidate === $allowed) {
                return true;
            }
        }
        return false;
    }

    private function safeText(mixed $value, int $limit): string
    {
        if (!is_string($value) || strlen($value) > 8192 || preg_match('//u', $value) !== 1) {
            return '';
        }
        $value = preg_replace('/[\p{Cc}\p{Cf}]+/u', ' ', $value) ?? '';
        $value = preg_replace('/\s+/u', ' ', $value) ?? '';
        $characters = preg_split('//u', trim($value), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $output = '';
        foreach (array_slice($characters, 0, $limit) as $character) {
            // Discord emoji, variation selectors and unsupported glyphs get a visible safe fallback.
            if ($character === "\u{26A1}" || preg_match('/[\x{FE00}-\x{FE0F}]/u', $character)) {
                continue;
            }
            if (preg_match('/\A[\x{20}-\x{7E}\x{A1}-\x{24F}\x{3000}-\x{30FF}\x{3400}-\x{9FFF}\x{FF01}-\x{FF60}]\z/u', $character) !== 1) {
                $output .= '?';
                continue;
            }
            if ($character !== ' ' && !($this->glyphCache[$character] ??= $this->hasGlyph($character))) {
                $output .= '?';
                continue;
            }
            $output .= $character;
        }
        return $output . (count($characters) > $limit ? '...' : '');
    }

    private function hasGlyph(string $character): bool
    {
        return $this->glyphFingerprint($this->font, $character) !== $this->missingFingerprint;
    }

    private function glyphFingerprint(string $font, string $character): string
    {
        $image = imagecreatetruecolor(64, 64);
        if ($image === false) { throw new RuntimeException('Card rendering is unavailable.'); }
        try {
            imagefill($image, 0, 0, 0xffffff);
            $result = $this->quiet(static fn () => imagettftext($image, 18, 0, 8, 40, 0, $font, $character));
            if ($result === false) { throw new RuntimeException('Card rendering is unavailable.'); }
            $pixels = '';
            for ($y = 0; $y < 64; ++$y) {
                for ($x = 0; $x < 64; ++$x) { $pixels .= pack('N', imagecolorat($image, $x, $y)); }
            }
            return hash('sha256', $pixels);
        } finally { imagedestroy($image); }
    }

    private function canvas(int $width, int $height): GdImage
    {
        if ($width < 1 || $width > self::MONTHLY_WIDTH || $height < 1 || $height > self::MAX_CANVAS_HEIGHT
            || $width * $height > self::MAX_CANVAS_PIXELS) { throw new RuntimeException('Card dimensions are unavailable.'); }
        $image = imagecreatetruecolor($width, $height);
        if (!$image instanceof GdImage) { throw new RuntimeException('Card rendering is unavailable.'); }
        $this->colors = ['white' => 0xffffff, 'ink' => 0x222222, 'muted' => 0x666666,
            'soft' => 0xf5f7fb, 'line' => 0xdde2eb, 'sun_bg' => 0xfff4f4, 'sat_bg' => 0xf0f6ff,
            'red' => 0xbf4449, 'blue' => 0x3766ac,
            'ic' => 0x6355a0, 'ic_light' => 0xdbd3f9, 'cap' => 0x418dbd, 'cap_light' => 0xb7def4,
            'led' => 0xea6d63, 'led_light' => 0xffbba8, 'metal' => 0x8f9db1, 'gold' => 0xe2ad38,
            'plug' => 0x354052, 'battery' => 0x4a966d];
        // A flood fill allocates another canvas-sized work area on PHP/GD.
        // The initial surface is uniformly blank, so a rectangle gives identical pixels.
        imagefilledrectangle($image, 0, 0, $width - 1, $height - 1, $this->colors['white']);
        return $image;
    }

    /** Existing electronic-component artwork, ported from the preserved pre-placeholder renderer. */
    private function component(GdImage $image, string $kind, int $cx, int $cy, float $scale): void
    {
        $p = static fn(float $n): int => (int)round($n * $scale);
        $rect = function(float $x1, float $y1, float $x2, float $y2, string $color) use ($image, $cx, $cy, $p): void {
            imagefilledrectangle($image, $cx+$p($x1), $cy+$p($y1), $cx+$p($x2), $cy+$p($y2), $this->colors[$color]);
        };
        $ellipse = function(float $x, float $y, float $w, float $h, string $color) use ($image, $cx, $cy, $p): void {
            imagefilledellipse($image, $cx+$p($x), $cy+$p($y), max(1,$p($w)), max(1,$p($h)), $this->colors[$color]);
        };
        if ($kind === 'IC') {
            foreach ([-30,-10,10,30] as $pin) { $rect(-52,$pin-3,-33,$pin+3,'metal'); $rect(33,$pin-3,52,$pin+3,'metal'); }
            $rect(-36,-48,36,48,'ic'); $rect(-27,-39,27,-31,'ic_light');
            $ellipse(-12,0,7,9,'white'); $ellipse(12,0,7,9,'white');
            imagearc($image, $cx, $cy+$p(10), $p(24), $p(17), 0, 180, $this->colors['white']);
        } elseif ($kind === 'コンデンサ') {
            $rect(-19,32,-14,62,'metal'); $rect(14,32,19,57,'metal');
            $rect(-34,-37,34,35,'cap'); $ellipse(0,35,68,20,'cap'); $ellipse(0,-37,68,22,'cap_light');
            $rect(20,-30,29,30,'cap_light'); $rect(-18,-40,18,-37,'cap'); $rect(-2,-48,1,-29,'cap');
            $ellipse(-13,0,7,9,'white'); $ellipse(7,0,7,9,'white');
            imagearc($image, $cx-$p(3), $cy+$p(10), $p(22), $p(16), 0, 180, $this->colors['white']);
        } elseif ($kind === 'LED') {
            $rect(-18,26,-12,65,'metal'); $rect(12,26,18,57,'metal');
            $ellipse(0,-20,70,70,'led'); $rect(-35,-20,35,27,'led'); $rect(-43,24,43,33,'led');
            $ellipse(-16,-32,12,24,'led_light');
            $ellipse(-12,0,7,9,'white'); $ellipse(12,0,7,9,'white');
            imagearc($image, $cx, $cy+$p(10), $p(24), $p(17), 0, 180, $this->colors['white']);
            foreach ([[-57,-49,-68,-60],[0,-68,0,-81],[57,-49,68,-60]] as [$a,$b,$c,$d]) {
                imagesetthickness($image, max(1,$p(3)));
                imageline($image, $cx+$p($a), $cy+$p($b), $cx+$p($c), $cy+$p($d), $this->colors['gold']);
            }
            imagesetthickness($image, 1);
        } elseif ($kind === 'プラグ') {
            // Centre and scale the preserved titleIcon plug (two pins, body and bent cord).
            $rect(-26, -52, -14, -20, 'metal'); $rect(14, -52, 26, -20, 'metal');
            $rect(-38, -24, 38, 16, 'plug'); $rect(-18, 20, 18, 32, 'plug');
            imagesetthickness($image, max(1, $p(12)));
            imageline($image, $cx, $cy + $p(32), $cx, $cy + $p(52), $this->colors['plug']);
            imageline($image, $cx, $cy + $p(52), $cx + $p(48), $cy + $p(52), $this->colors['plug']);
            imagesetthickness($image, 1);
        } elseif ($kind === '電池') {
            // The preserved titleIcon battery keeps its terminal, white window and charge mark.
            $rect(-14, -52, 14, -40, 'metal'); $rect(-38, -40, 38, 48, 'battery');
            // Keep a one-pixel outline when the detail window rounds to the body's width.
            $windowHalfWidth = max(1, min($p(26), $p(38) - 1));
            imagefilledrectangle($image, $cx - $windowHalfWidth, $cy + $p(-28),
                $cx + $windowHalfWidth, $cy + $p(32), $this->colors['white']);
            $rect(-22, 0, 22, 28, 'battery');
            imageline($image, $cx + $p(-10), $cy + $p(-16), $cx + $p(10), $cy + $p(-16), $this->colors['battery']);
            imageline($image, $cx + $p(2), $cy + $p(-24), $cx + $p(2), $cy + $p(-8), $this->colors['battery']);
        }
    }
    private function text(GdImage $image, string $text, int $size, int $x, int $y, string $color): void
    {
        $result = $this->quiet(fn () => imagettftext($image, $size, 0, $x, $y, $this->colors[$color], $this->font, $text));
        if ($result === false) { throw new RuntimeException('Card rendering failed.'); }
    }

    private function width(string $text, int $size): int
    {
        $box = $this->quiet(fn () => imagettfbbox($size, 0, $this->font, $text));
        if (!is_array($box)) { throw new RuntimeException('Card rendering failed.'); }
        return max($box[0], $box[2], $box[4], $box[6]) - min($box[0], $box[2], $box[4], $box[6]);
    }

    private function fit(string $text, int $size, int $maxWidth): array
    {
        while ($size > 16 && $this->width($text, $size) > $maxWidth) { --$size; }
        $characters = preg_split('//u', $text, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        if ($this->width($text, $size) > $maxWidth) {
            while ($characters !== [] && $this->width(implode('', $characters) . '...', $size) > $maxWidth) { array_pop($characters); }
            $text = implode('', $characters) . '...';
        }
        return [$text, $size];
    }

    private function fitText(GdImage $image, string $text, int $size, int $x, int $y, int $maxWidth, string $color): void
    {
        [$text, $size] = $this->fit($text, $size, $maxWidth);
        $this->text($image, $text, $size, $x, $y, $color);
    }

    private function centerText(GdImage $image, string $text, int $size, int $x, int $y, string $color): void
    {
        $this->text($image, $text, $size, $x - intdiv($this->width($text, $size), 2), $y, $color);
    }

    private function centerFitText(GdImage $image, string $text, int $size, int $x, int $y, int $maxWidth, string $color): void
    {
        [$text, $size] = $this->fit($text, $size, $maxWidth);
        $this->centerText($image, $text, $size, $x, $y, $color);
    }

    private function quiet(callable $callback): mixed
    {
        set_error_handler(static fn (): bool => true);
        try { return $callback(); }
        catch (Throwable) { throw new RuntimeException('Card rendering failed.'); }
        finally { restore_error_handler(); }
    }

    private function encode(GdImage $image): string
    {
        $level = ob_get_level();
        ob_start();
        try {
            if (!$this->quiet(static fn () => imagepng($image))) { throw new RuntimeException('Card encoding failed.'); }
            $bytes = ob_get_contents();
            if (!is_string($bytes) || !str_starts_with($bytes, "\x89PNG\r\n\x1a\n") || strlen($bytes) > 8_000_000) {
                throw new RuntimeException('Card encoding failed.');
            }
            return $bytes;
        } finally { while (ob_get_level() > $level) { ob_end_clean(); } }
    }
}
