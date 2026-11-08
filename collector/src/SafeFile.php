<?php

declare(strict_types=1);

namespace LogWatch;

/**
 * Reading files that a less-privileged user controls (home directories, site
 * storage) while running as root. A planted symlink to /dev/zero, a FIFO or a
 * huge file must not hang the collector or blow its memory.
 */
final class SafeFile
{
    /** True when the path (after following links) is a regular file no bigger than $maxBytes. */
    public static function isReadableRegular(string $path, int $maxBytes = PHP_INT_MAX): bool
    {
        $real = realpath($path);
        if ($real === false || !is_file($real) || !is_readable($real)) {
            return false;
        }
        $st = @stat($real);
        // 0100000 = S_IFREG: a real file, not a device, FIFO or socket.
        return $st !== false && ($st['mode'] & 0170000) === 0100000 && $st['size'] <= $maxBytes;
    }

    /** Contents of a small regular file, or null when it isn't one. */
    public static function read(string $path, int $maxBytes = 1048576): ?string
    {
        if (!self::isReadableRegular($path, $maxBytes)) {
            return null;
        }
        $h = @fopen($path, 'rb');
        if ($h === false) {
            return null;
        }
        $data = (string) fread($h, $maxBytes);
        fclose($h);
        return $data;
    }

    /** @return list<string> lines of a small regular file, or [] */
    public static function lines(string $path, int $maxBytes = 1048576): array
    {
        $data = self::read($path, $maxBytes);
        return $data === null ? [] : array_values(array_filter(preg_split('/\R/', $data), fn ($l) => $l !== ''));
    }

    /** sha256 prefix of a small regular file, or null. */
    public static function hash(string $path, int $maxBytes = 1048576): ?string
    {
        $data = self::read($path, $maxBytes);
        return $data === null ? null : substr(hash('sha256', $data), 0, 16);
    }
}
