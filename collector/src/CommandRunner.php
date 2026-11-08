<?php

declare(strict_types=1);

namespace LogWatch;

/**
 * Runs system commands without a shell. In fixture mode, reads canned output
 * from <fixtureDir>/<name>.txt instead, so tests never touch the real system.
 */
final class CommandRunner
{
    public function __construct(private readonly ?string $fixtureDir = null)
    {
    }

    /**
     * @param list<string> $argv
     * @return array{out:string,err:string,code:int}|null  null when the command can't be run
     */
    public function run(string $name, array $argv): ?array
    {
        if ($this->fixtureDir !== null) {
            $f = "{$this->fixtureDir}/$name.txt";
            if (!is_file($f)) {
                return null;
            }
            $err = is_file("{$this->fixtureDir}/$name.err") ? (string) file_get_contents("{$this->fixtureDir}/$name.err") : '';
            return ['out' => (string) file_get_contents($f), 'err' => $err, 'code' => 0];
        }
        $proc = $this->open($argv, $pipes);
        if ($proc === null) {
            return null;
        }
        $out = (string) stream_get_contents($pipes[1]);
        $err = (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        return ['out' => $out, 'err' => $err, 'code' => proc_close($proc)];
    }

    /**
     * Stream stdout line by line, for commands with large output (journalctl).
     * @param list<string> $argv
     * @param callable(string):void $onLine
     * @return bool false when the command can't be run
     */
    public function stream(string $name, array $argv, callable $onLine): bool
    {
        if ($this->fixtureDir !== null) {
            $f = "{$this->fixtureDir}/$name.txt";
            if (!is_file($f)) {
                return false;
            }
            foreach (LogReader::lines($f) as $line) {
                $onLine($line);
            }
            return true;
        }
        $proc = $this->open($argv, $pipes);
        if ($proc === null) {
            return false;
        }
        while (($line = fgets($pipes[1])) !== false) {
            $onLine(rtrim($line, "\r\n"));
        }
        fclose($pipes[1]);
        stream_get_contents($pipes[2]);
        fclose($pipes[2]);
        proc_close($proc);
        return true;
    }

    /** @return resource|null */
    private function open(array $argv, ?array &$pipes)
    {
        if (!is_executable($argv[0])) {
            $resolved = trim((string) shell_exec('command -v ' . escapeshellarg($argv[0]) . ' 2>/dev/null'));
            if ($resolved === '') {
                return null;
            }
            $argv[0] = $resolved;
        }
        $proc = @proc_open($argv, [0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null, ['LC_ALL' => 'C', 'PATH' => '/usr/sbin:/usr/bin:/sbin:/bin', 'SYSTEMD_COLORS' => '0']);
        return is_resource($proc) ? $proc : null;
    }
}
