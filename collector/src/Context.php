<?php

declare(strict_types=1);

namespace LogWatch;

/**
 * Everything a section needs for one run: the time window, where files live,
 * how to run commands, the anonymiser and the on-disk state.
 */
final class Context
{
    public function __construct(
        /** Path prefix for every file read. '' on a real server; a fixture tree in tests. */
        public readonly string $root,
        public readonly string $date,
        public readonly int $start,
        public readonly int $end,
        public readonly Anon $anon,
        public readonly CommandRunner $cmd,
        public readonly State $state,
        /** In fixture mode, file mtimes are meaningless, so we don't use them to skip files. */
        public readonly bool $fixtureMode,
        public readonly array $config,
    ) {
    }

    public function path(string $absolute): string
    {
        return $this->root . $absolute;
    }

    public function inWindow(?int $ts): bool
    {
        return $ts !== null && $ts >= $this->start && $ts < $this->end;
    }

    /** True when a file might hold lines inside the window (judged by its last write). */
    public function mayCover(string $realPath): bool
    {
        if ($this->fixtureMode) {
            return true;
        }
        $m = @filemtime($realPath);
        return $m !== false && $m >= $this->start;
    }

    public function cfg(string $key, mixed $default): mixed
    {
        return $this->config[$key] ?? $default;
    }

    /** glob() under the root prefix, returning paths without the prefix. */
    public function glob(string $pattern): array
    {
        $found = glob($this->path($pattern)) ?: [];
        $len = strlen($this->root);
        return array_map(fn ($p) => substr($p, $len), $found);
    }
}
