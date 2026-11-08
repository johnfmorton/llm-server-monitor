<?php

declare(strict_types=1);

namespace LogWatch;

/**
 * Reading files that a less-privileged user controls (home directories, site
 * storage) while running as root. A planted symlink to /dev/zero, a FIFO, a
 * huge file, or a path swapped between check and open must not hang the
 * collector or blow its memory.
 *
 * Every check is made on the open handle (fstat), not on the path, so there is
 * no gap between checking a file and reading it. The open is non-blocking, so
 * a FIFO can't stall it.
 */
final class SafeFile
{
    /**
     * Open a regular file for reading, or return null.
     * @return resource|null
     */
    public static function open(string $path, int $maxBytes = PHP_INT_MAX)
    {
        $real = realpath($path);
        if ($real === false) {
            return null;
        }
        // 'n' = O_NONBLOCK: opening a FIFO returns at once instead of waiting for a writer.
        $h = @fopen($real, 'rbn');
        if ($h === false) {
            return null;
        }
        $st = fstat($h);
        // 0100000 = S_IFREG: a real file, not a device, FIFO or socket.
        if ($st === false || ($st['mode'] & 0170000) !== 0100000 || $st['size'] > $maxBytes) {
            fclose($h);
            return null;
        }
        stream_set_blocking($h, true);
        return $h;
    }

    /** Contents of a small regular file, or null when it isn't one. */
    public static function read(string $path, int $maxBytes = 1048576): ?string
    {
        $h = self::open($path, $maxBytes);
        if ($h === null) {
            return null;
        }
        $data = (string) stream_get_contents($h, $maxBytes);
        fclose($h);
        return $data;
    }

    /** @return list<string> non-empty lines of a small regular file, or [] */
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

    /**
     * Lines of a regular file, read as plain text (never decompressed), with a
     * bounded line length. When the file is larger than $tailBytes, only its
     * end is read.
     * @return \Generator<string>
     */
    public static function tailLines(string $path, int $tailBytes, int $maxLine = 65536): \Generator
    {
        $h = self::open($path);
        if ($h === null) {
            return;
        }
        try {
            $size = (int) fstat($h)['size'];
            if ($size > $tailBytes) {
                fseek($h, $size - $tailBytes);
                fgets($h, $maxLine); // skip the partial line
            }
            $read = 0;
            while ($read < $tailBytes && ($line = fgets($h, $maxLine)) !== false) {
                $read += strlen($line);
                yield rtrim($line, "\r\n");
            }
        } finally {
            fclose($h);
        }
    }
}
