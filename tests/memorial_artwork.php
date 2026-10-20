<?php
declare(strict_types=1);
require_once __DIR__.'/artifact_directory.php';
require dirname(__DIR__) . '/bootstrap.php';

use RadioStamp\Cards\{CardRenderer, MemorialArtwork};
use RadioStamp\Core\ParticipationDays;

$checks = 0; $scenarios = []; $links = [];
$same = static function(mixed $expected, mixed $actual, string $why) use (&$checks): void {
    ++$checks;
    if ($expected !== $actual) { throw new RuntimeException($why . ' expected=' . json_encode($expected) . ' actual=' . json_encode($actual)); }
};
$assert = static function(bool $ok, string $why) use ($same): void { $same(true, $ok, $why); };
$fonts = ['/usr/share/fonts/ipa-gothic/ipag.ttf', '/usr/share/fonts/ipa-pgothic/ipagp.ttf', '/usr/share/fonts/ja/TrueType/kochi-gothic-subst.ttf',
    '/usr/share/fonts/vlgothic/VL-Gothic-Regular.ttf', '/usr/share/fonts/opentype/noto/NotoSansCJK-Regular.ttc', '/usr/share/fonts/ipaexg/ipaexg.ttf',
    'C:/Windows/Fonts/BIZ-UDGothicR.ttc', 'C:/Windows/Fonts/msgothic.ttc'];
$temporary = str_replace('\\', '/', sys_get_temp_dir());
$root = $temporary . '/radio-memorial-artwork-' . bin2hex(random_bytes(8));
mkdir($root, 0700); $art = $root . '/art'; mkdir($art, 0700);
$stage = getenv('RADIO_STAMP_TEST_ARTIFACT_DIR') ?: getenv('RADIO_STAMP_TEST_ARTIFACT_DIR');
if (!is_dir($stage) && !mkdir($stage, 0700, true)) { throw new RuntimeException('test_artifact_directory_unavailable'); }
$manifest = static function(string|array $entries) use ($art): void {
    file_put_contents($art . '/active.json', is_string($entries) ? $entries : json_encode((object)$entries, JSON_THROW_ON_ERROR));
};
$data = static function(int $days = 365): array {
    return ['user_id' => '900000000000000101', 'display_name' => '架空の利用者', 'month' => '2026-10', 'month_count' => 0,
        'total_count' => $days, 'days' => [], 'month_days' => 0, 'total_days' => $days,
        'awards' => ParticipationDays::reached($days), 'milestone_basis' => 'attendance_days_jst'];
};
$makePng = static function(int $width, int $height, int $red, int $green, int $blue): string {
    $image = imagecreatetruecolor($width, $height);
    try {
        imagefill($image, 0, 0, imagecolorallocate($image, $red, $green, $blue));
        ob_start();
        try { if (!imagepng($image)) { throw new RuntimeException('fixture_png_failed'); } return ob_get_contents(); }
        finally { ob_end_clean(); }
    } finally { imagedestroy($image); }
};
$reject = static function(Closure $call, string $why) use ($same): void {
    $level = ob_get_level(); ob_start(); $caught = null; $warning = false;
    set_error_handler(static function() use (&$warning): bool { $warning = true; return true; });
    try { try { $call(); } catch (Throwable $error) { $caught = $error; } }
    finally { restore_error_handler(); $output = ob_get_clean(); }
    $same(RuntimeException::class, $caught === null ? null : $caught::class, $why . ' fixed exception class');
    $same('Memorial artwork is unavailable.', $caught?->getMessage(), $why . ' fixed message');
    $same(false, $warning, $why . ' no IO/decode warning escapes');
    $same('', $output, $why . ' no partial image/output'); $same($level, ob_get_level(), $why . ' buffer restored');
};
$remove = static function(string $path) use (&$remove, $root): void {
    // Recursive removal is confined to this generated temporary fixture and never follows links.
    $normalized = str_replace('\\', '/', $path);
    if ($normalized !== $root && !str_starts_with($normalized, $root . '/')) { throw new RuntimeException('fixture_cleanup_scope'); }
    if (is_link($path) || !is_dir($path)) { if (file_exists($path) || is_link($path)) { unlink($path); } return; }
    foreach (scandir($path) as $name) { if ($name !== '.' && $name !== '..') { $remove($path . '/' . $name); } }
    rmdir($path);
};
try {
    $manifest('{}'); $loader = new MemorialArtwork($art); $renderer = new CardRenderer($fonts, $art);
    $defaultRenderer = new CardRenderer($fonts);
    $same('{}', trim(file_get_contents(dirname(__DIR__) . '/assets/memorial-cards/active.json')), 'production manifest stays empty');
    $placeholderHashes = [];
    foreach (ParticipationDays::MILESTONES as $day) {
        $same(null, $loader->load($day), 'empty map returns no artwork for ' . $day);
        $placeholder = $renderer->memorial($data(), $day);
        $same($defaultRenderer->memorial($data(), $day), $placeholder, 'existing constructor and fixed default root preserve placeholder ' . $day);
        $image = imagecreatefromstring($placeholder);
        $assert($image instanceof GdImage, 'placeholder decodes ' . $day);
        try { $same([1200, 800], [imagesx($image), imagesy($image)], 'placeholder dimensions ' . $day); } finally { imagedestroy($image); }
        $placeholderHashes[] = hash('sha256', $placeholder);
    }
    $same(6, count(array_unique($placeholderHashes)), 'six day placeholders remain distinct');
    $scenarios['empty_and_default_placeholder'] = true;

    $v1 = $makePng(1200, 800, 31, 97, 173); $v2 = $makePng(1200, 800, 57, 133, 73);
    file_put_contents($art . '/day-10-v1.png', $v1); file_put_contents($art . '/day-10-v2.png', $v2);
    $manifest(['10' => 'day-10-v1.png']);
    $same($v1, $renderer->memorial($data(), 10), 'completed PNG returned byte for byte without overlay');
    $same($placeholderHashes[1], hash('sha256', $renderer->memorial($data(), 30)), 'unspecified day keeps placeholder');
    $first = $renderer->memorial($data(), 10);
    $same($first, $renderer->memorial($data(), 10), 'new request can reacquire same art without consumption');
    $manifest(['10' => 'day-10-v2.png']); $same($v2, $renderer->memorial($data(), 10), 'manifest switch selects new version');
    $same($v1, file_get_contents($art . '/day-10-v1.png'), 'old version is retained'); $same($v1, $first, 'already downloaded bytes unchanged');
    $entries = [];
    foreach (ParticipationDays::MILESTONES as $day) { $entries[(string)$day] = 'day-' . $day . '-v1.png'; file_put_contents($art . '/' . $entries[(string)$day], $v1); }
    $manifest($entries);
    foreach (ParticipationDays::MILESTONES as $day) { $same($v1, $renderer->memorial($data(), $day), 'all six canonical days accept matching art ' . $day); }
    $scenarios['exact_png_reacquisition_and_version_preservation'] = true;

    $badRenderer = new CardRenderer($fonts, $root . '/missing');
    foreach ([['days' => 9, 'day' => 10], ['days' => 365, 'day' => 1]] as $case) {
        $caught = null;
        try { $badRenderer->memorial($data($case['days']), $case['day']); } catch (Throwable $error) { $caught = $error; }
        $same(InvalidArgumentException::class, $caught === null ? null : $caught::class, 'eligibility rejection precedes artwork root access');
        $same('This day milestone is not reached.', $caught?->getMessage(), 'eligibility failure is never converted to missing artwork');
    }
    $invalidBasis = $data(); $invalidBasis['milestone_basis'] = 'attendance_count';
    try { $badRenderer->memorial($invalidBasis, 10); throw new RuntimeException('invalid_basis_was_accepted'); }
    catch (InvalidArgumentException $error) { $same('This day milestone is not reached.', $error->getMessage(), 'day basis validated before root access'); }
    $reject(fn() => $badRenderer->memorial($data(), 10), 'qualified request detects missing artwork root');
    $scenarios['qualification_before_artwork_access'] = true;

    foreach (['[]', 'null', '"x"', '{broken', '{"1":"day-1-v1.png"}', '{"010":"day-10-v1.png"}', '{"10.0":"day-10-v1.png"}',
        '{"10":null}', '{"10":[]}', '{"10":123}', '{"10":"day-10-v1.png","10":"day-10-v2.png"}',
        '{"10":"day-10-v1.png","\\u0031\\u0030":"day-10-v2.png"}'] as $index => $json) {
        $manifest($json); $reject(fn() => $loader->load(10), 'invalid manifest case ' . $index);
    }
    $manifest('{"\\u0031\\u0030":"day-10-v1.png"}'); $same($v1, $loader->load(10), 'unambiguous escaped JSON key is accepted');
    foreach (['../day-10-v1.png', 'sub/day-10-v1.png', '/day-10-v1.png', 'C:\\private\\day-10-v1.png',
        'https://example.invalid/day-10-v1.png', 'day-30-v1.png', 'day-10.png', 'day-10-v0.png', 'day-10-v01.png', 'day-10-v1.PNG',
        "day-10-v1.png\0", str_repeat('x', 129)] as $index => $filename) {
        $manifest(['10' => $filename]); $reject(fn() => $loader->load(10), 'invalid filename case ' . $index);
    }
    $manifest(['10' => 'day-10-v1.png', '30' => '../day-30-v1.png']);
    $reject(fn() => $loader->load(10), 'unrequested invalid manifest entry also rejected');
    $manifest('{}' . str_repeat(' ', 4094)); $same(null, $loader->load(10), '4096 byte JSON accepted');
    $manifest('{}' . str_repeat(' ', 4095)); $reject(fn() => $loader->load(10), '4097 byte JSON rejected');
    $manifest(''); $reject(fn() => $loader->load(10), 'empty manifest rejected');
    unlink($art . '/active.json'); $reject(fn() => $loader->load(10), 'missing manifest rejected');
    $scenarios['manifest_and_filename_boundaries'] = true;

    $manifest(['10' => 'day-10-v3.png']); $reject(fn() => $loader->load(10), 'specified missing PNG rejected');
    mkdir($art . '/day-10-v3.png'); $reject(fn() => $loader->load(10), 'directory instead of PNG rejected'); rmdir($art . '/day-10-v3.png');
    $badPngs = ['', 'not an image', substr($v1, 0, 50), $makePng(1199, 800, 1, 2, 3), $makePng(1200, 799, 1, 2, 3)];
    $jpeg = imagecreatetruecolor(1200, 800); ob_start(); imagejpeg($jpeg); $badPngs[] = ob_get_clean(); imagedestroy($jpeg);
    foreach ($badPngs as $index => $bytes) { file_put_contents($art . '/day-10-v3.png', $bytes); $reject(fn() => $loader->load(10), 'invalid PNG case ' . $index); }
    // A valid ancillary text chunk makes the exact byte limit test a real decodable PNG.
    $chunkSize = 1048576 - strlen($v1); $text = "Fictional fixture\0" . str_repeat('x', $chunkSize - 12 - strlen("Fictional fixture\0"));
    $chunk = pack('N', strlen($text)) . 'tEXt' . $text . pack('N', crc32('tEXt' . $text));
    $limitPng = substr($v1, 0, -12) . $chunk . substr($v1, -12);
    $same(1048576, strlen($limitPng), 'valid exact-limit fixture size'); file_put_contents($art . '/day-10-v3.png', $limitPng);
    $same(hash('sha256', $limitPng), hash('sha256', $renderer->memorial($data(), 10)), '1MiB completed PNG is accepted unchanged');
    file_put_contents($art . '/day-10-v3.png', $limitPng . 'x'); $reject(fn() => $loader->load(10), '1MiB plus one byte rejected');
    $scenarios['png_decode_dimensions_and_exact_1mib_limit'] = true;

    $outside = $root . '/outside'; mkdir($outside, 0700); file_put_contents($outside . '/day-10-v1.png', $v1); file_put_contents($outside . '/active.json', '{"10":"day-10-v1.png"}');
    $link = $root . '/probe-link';
    $symlinkAvailable = function_exists('symlink') && @symlink($outside . '/day-10-v1.png', $link);
    if ($symlinkAvailable) {
        unlink($link); unlink($art . '/day-10-v3.png');
        $assert(@symlink($outside . '/day-10-v1.png', $art . '/day-10-v3.png'), 'PNG symlink fixture');
        $reject(fn() => $loader->load(10), 'PNG symlink rejected'); unlink($art . '/day-10-v3.png');
        $assert(@symlink($outside . '/missing.png', $art . '/day-10-v3.png'), 'dangling PNG symlink fixture');
        $reject(fn() => $loader->load(10), 'dangling PNG symlink rejected'); unlink($art . '/day-10-v3.png');
        unlink($art . '/active.json'); $assert(@symlink($outside . '/active.json', $art . '/active.json'), 'manifest symlink fixture');
        $reject(fn() => $loader->load(10), 'manifest symlink rejected'); unlink($art . '/active.json'); $manifest('{}');
        $rootLink = $root . '/linked-art'; $assert(@symlink($outside, $rootLink), 'root symlink fixture');
        $reject(fn() => (new MemorialArtwork($rootLink))->load(10), 'artwork root symlink rejected'); unlink($rootLink);
        $parentLink = $root . '/linked-parent'; $assert(@symlink($root, $parentLink), 'parent symlink fixture');
        $reject(fn() => (new MemorialArtwork($parentLink . '/art'))->load(10), 'artwork ancestor symlink rejected'); unlink($parentLink);
    }
    $scenarios['symlink_rejection_executed'] = $symlinkAvailable;
    $scenarios['symlink_unavailable'] = !$symlinkAvailable;

    $manifest('{}');
    $same($v1, file_get_contents($art . '/day-10-v1.png'), 'all probes retain old version');
    $same($v2, file_get_contents($art . '/day-10-v2.png'), 'all probes retain newer version');
    file_put_contents($stage . '/fixture-only-completed-v1.png', $v1); file_put_contents($stage . '/fixture-only-completed-v2.png', $v2);
    $result = ['status' => 'LOCAL_MEMORIAL_ARTWORK_VERIFIED_NOT_DEPLOYED', 'assertions' => $checks, 'php' => PHP_VERSION,
        'sqlite' => SQLite3::version()['versionString'], 'scenarios' => $scenarios, 'real_network_calls' => 0, 'real_db_reads' => 0,
        'deployed' => false, 'formal_artwork_created' => false, 'production_manifest_empty' => true,
        'fixture_png_sha256' => [hash('sha256', $v1), hash('sha256', $v2)]];
    file_put_contents($stage . '/memorial-artwork.safe.json', json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n");
    echo "Memorial artwork: $checks assertions passed (private completed PNGs, eligibility first, exact limits, version retention; local only).\n";
    if (!$symlinkAvailable) { echo "Memorial artwork: symlink creation unavailable; executable link cases explicitly unverified.\n"; }
} finally {
    if (!str_starts_with($root, $temporary . '/radio-memorial-artwork-')) { throw new RuntimeException('fixture_cleanup_root'); }
    $remove($root);
}
