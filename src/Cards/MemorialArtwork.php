<?php
declare(strict_types=1);
namespace RadioStamp\Cards;

use RadioStamp\Core\ParticipationDays;
use RuntimeException;
use Throwable;

/** Completed private-release PNGs only; no URL, public store, or writable artwork state. */
final class MemorialArtwork
{
    private const ERROR = 'Memorial artwork is unavailable.';
    private const MAX_MANIFEST_BYTES = 4096;
    private const MAX_PNG_BYTES = 1048576;
    private readonly string $root;

    /** The optional root is a code-only fixture seam, never a request/configuration value. */
    public function __construct(?string $trustedRoot = null)
    {
        $this->root = rtrim(str_replace('\\', '/', $trustedRoot ?? dirname(__DIR__, 2) . '/assets/memorial-cards'), '/');
    }

    /** Call only after the participant's current day eligibility has been validated. */
    public function load(int $milestone): ?string
    {
        // IO and image-library warnings can include private release paths.
        set_error_handler(static fn(): bool => true);
        try {
            if (!in_array($milestone, ParticipationDays::MILESTONES, true)) { $this->fail(); }
            $json = $this->read('active.json', self::MAX_MANIFEST_BYTES);
            $manifest = json_decode($json, false, 4, JSON_THROW_ON_ERROR);
            if (!$manifest instanceof \stdClass) { $this->fail(); }
            $entries = get_object_vars($manifest);
            foreach ($entries as $day => $filename) {
                if (!in_array((int)$day, ParticipationDays::MILESTONES, true) || (string)(int)$day !== (string)$day
                    || !is_string($filename) || strlen($filename) > 128
                    || preg_match('/\Aday-' . $day . '-v[1-9][0-9]*\.png\z/D', $filename) !== 1) { $this->fail(); }
            }
            // Valid values are simple basenames, so every JSON string followed by a colon is a key.
            // Reject duplicate (including escaped) keys instead of silently selecting a last value.
            if (preg_match_all('~"(?:[^"\\\\]|\\\\.)*"\s*:~s', $json) !== count($entries)) { $this->fail(); }
            $filename = $entries[$milestone] ?? null;
            if ($filename === null) { return null; }
            $png = $this->read($filename, self::MAX_PNG_BYTES);
            if (!str_starts_with($png, "\x89PNG\r\n\x1a\n")) { $this->fail(); }
            $info = getimagesizefromstring($png);
            if (!is_array($info) || $info[0] !== 1200 || $info[1] !== 800 || $info[2] !== IMAGETYPE_PNG) { $this->fail(); }
            $image = imagecreatefromstring($png);
            if (!$image instanceof \GdImage) { $this->fail(); }
            try {
                if (imagesx($image) !== 1200 || imagesy($image) !== 800) { $this->fail(); }
            } finally { imagedestroy($image); }
            return $png;
        } catch (Throwable) {
            throw new RuntimeException(self::ERROR);
        } finally { restore_error_handler(); }
    }

    private function guardRoot(): void
    {
        if (preg_match('~\A(?:[A-Za-z]:/|/(?!/))~D', $this->root) !== 1) { $this->fail(); }
        clearstatcache(true, $this->root);
        if (!is_dir($this->root) || !$this->samePath(realpath($this->root), $this->root)) { $this->fail(); }
        for ($path = $this->root; ; $path = $parent) {
            clearstatcache(true, $path);
            if (is_link($path)) { $this->fail(); }
            $parent = str_replace('\\', '/', dirname($path));
            if ($parent === $path) { break; }
        }
    }

    private function read(string $basename, int $maximum): string
    {
        $this->guardRoot();
        $path = $this->root . '/' . $basename;
        clearstatcache(true, $path);
        $before = @lstat($path);
        if (!is_array($before) || ($before['mode'] & 0170000) !== 0100000 || is_link($path)
            || !$this->samePath(realpath($path), $path) || $before['size'] < 1 || $before['size'] > $maximum) { $this->fail(); }
        $handle = @fopen($path, 'rb');
        if ($handle === false) { $this->fail(); }
        try {
            if (!$this->sameFile($before, fstat($handle))) { $this->fail(); }
            // Recheck paths after opening and before consuming any bytes.
            $this->guardRoot();
            clearstatcache(true, $path);
            if (is_link($path) || !$this->samePath(realpath($path), $path) || !$this->sameFile($before, @lstat($path))) { $this->fail(); }
            $bytes = stream_get_contents($handle, $maximum + 1);
            if (!is_string($bytes) || strlen($bytes) !== $before['size'] || strlen($bytes) > $maximum
                || !$this->sameFile($before, fstat($handle))) { $this->fail(); }
            clearstatcache(true, $path);
            if (is_link($path) || !$this->samePath(realpath($path), $path) || !$this->sameFile($before, @lstat($path))) { $this->fail(); }
            return $bytes;
        } finally { fclose($handle); }
    }

    private function sameFile(array $expected, array|false $actual): bool
    {
        if (!is_array($actual)) { return false; }
        foreach (['dev', 'ino', 'mode', 'size', 'mtime', 'ctime'] as $key) {
            if ($expected[$key] !== $actual[$key]) { return false; }
        }
        return true;
    }

    private function samePath(string|false $actual, string $expected): bool
    {
        if (!is_string($actual)) { return false; }
        $actual = str_replace('\\', '/', $actual);
        return PHP_OS_FAMILY === 'Windows' ? strcasecmp($actual, $expected) === 0 : $actual === $expected;
    }

    private function fail(): never { throw new RuntimeException(self::ERROR); }
}
