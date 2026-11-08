<?php

declare(strict_types=1);

namespace LogWatch;

final class LogReader
{
    /**
     * The current file plus rotated siblings (.1, .2.gz, …) that may hold lines
     * inside the window, newest first. Stops at the first file last written
     * before the window starts.
     *
     * @return list<string> real paths
     */
    public static function rotated(Context $ctx, string $base, int $max = 10): array
    {
        $files = [];
        $candidates = [$base];
        for ($i = 1; $i <= $max; $i++) {
            $candidates[] = "$base.$i";
            $candidates[] = "$base.$i.gz";
        }
        foreach ($candidates as $c) {
            $p = $ctx->path($c);
            if (!is_file($p)) {
                continue;
            }
            if (!$ctx->mayCover($p)) {
                break;
            }
            $files[] = $p;
        }
        return $files;
    }

    /**
     * Lines of a plain or gzipped file. gzopen reads plain files transparently.
     * @param int $tailBytes when > 0 and the file is plain, read only the last N bytes
     * @return \Generator<string>
     */
    public static function lines(string $file, int $tailBytes = 0): \Generator
    {
        $h = @gzopen($file, 'rb');
        if ($h === false) {
            return;
        }
        try {
            if ($tailBytes > 0 && !str_ends_with($file, '.gz')) {
                $size = (int) @filesize($file);
                if ($size > $tailBytes) {
                    gzseek($h, $size - $tailBytes);
                    gzgets($h); // skip the partial line
                }
            }
            while (($line = gzgets($h)) !== false) {
                yield rtrim($line, "\r\n");
            }
        } finally {
            gzclose($h);
        }
    }
}
