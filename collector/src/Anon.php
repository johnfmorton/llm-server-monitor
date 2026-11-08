<?php

declare(strict_types=1);

namespace LogWatch;

/**
 * Turns IPs into keyed tokens and scrubs free text before it leaves the server.
 * Real IPs only ever go into the local token->IP map (see State::writeIpMap).
 */
final class Anon
{
    /** @var array<string,string> token => canonical IP, for the local lookup table */
    private array $map = [];

    /** @var array<string,array> */
    private array $describeCache = [];

    public function __construct(
        private readonly string $secret,
        private readonly ?GeoLookup $geo = null,
    ) {
        if (strlen($secret) < 32) {
            throw new \RuntimeException('HMAC secret is too short (need at least 32 characters).');
        }
    }

    public static function canonical(string $ip): string
    {
        $ip = trim($ip, '[]');
        $bin = @inet_pton($ip);
        return $bin === false ? strtolower($ip) : (string) inet_ntop($bin);
    }

    public function token(string $ip): string
    {
        $c = self::canonical($ip);
        $t = substr(hash_hmac('sha256', $c, $this->secret), 0, 12);
        $this->map[$t] = $c;
        return $t;
    }

    /** Token plus coarse context. This is the only form an IP takes in the output. */
    public function describe(string $ip): array
    {
        $c = self::canonical($ip);
        if (isset($this->describeCache[$c])) {
            return $this->describeCache[$c];
        }
        $d = ['ip' => $this->token($c), 'scope' => self::scope($c)];
        if ($this->geo !== null && $d['scope'] === 'public') {
            $g = $this->geo->lookup($c);
            if ($g !== null) {
                $d += $g;
            }
        }
        return $this->describeCache[$c] = $d;
    }

    public static function isTailnet(string $ip): bool
    {
        $bin = @inet_pton(trim($ip, '[]'));
        if ($bin === false) {
            return false;
        }
        if (strlen($bin) === 4) {
            // 100.64.0.0/10
            return ord($bin[0]) === 100 && (ord($bin[1]) & 0xC0) === 64;
        }
        // fd7a:115c:a1e0::/48
        return substr($bin, 0, 6) === "\xfd\x7a\x11\x5c\xa1\xe0";
    }

    /** tailnet | loopback | private | public | invalid */
    public static function scope(string $ip): string
    {
        $ip = trim($ip, '[]');
        if (filter_var($ip, FILTER_VALIDATE_IP) === false) {
            return 'invalid';
        }
        if (self::isTailnet($ip)) {
            return 'tailnet';
        }
        if ($ip === '::1' || str_starts_with($ip, '127.')) {
            return 'loopback';
        }
        $public = filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE);
        return $public === false ? 'private' : 'public';
    }

    /**
     * Make outsider-controlled or possibly sensitive text safe to send:
     * strip control characters, tokenise IPs, drop emails and long secrets,
     * shorten Forge release paths, and truncate.
     */
    public function scrub(string $s, int $max = 200): string
    {
        $s = (string) preg_replace('/[\x00-\x1F\x7F]+/u', ' ', $s);
        if (!mb_check_encoding($s, 'UTF-8')) {
            $s = mb_convert_encoding($s, 'UTF-8', 'UTF-8');
        }
        $s = self::shortenPaths($s);
        $s = (string) preg_replace_callback(
            // Not after "Name/", so version strings like Chrome/131.0.0.0 survive.
            '/(?<![\w.])(?<![A-Za-z]\/)(\d{1,3}(?:\.\d{1,3}){3})(?![\w.])/',
            fn ($m) => filter_var($m[1], FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) ? 'ip:' . $this->token($m[1]) : $m[1],
            $s
        );
        $s = (string) preg_replace_callback(
            '/(?<![\w:])((?:[0-9a-f]{0,4}:){2,7}[0-9a-f]{0,4})(?![\w:])/i',
            fn ($m) => filter_var($m[1], FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) ? 'ip:' . $this->token($m[1]) : $m[1],
            $s
        );
        $s = (string) preg_replace('/[A-Za-z0-9._%+\-]+@[A-Za-z0-9.\-]+\.[A-Za-z]{2,}/', '[email]', $s);
        // Long unbroken runs look like secrets. "/" is excluded so file paths survive.
        $s = (string) preg_replace('/(?<![A-Za-z0-9])[A-Za-z0-9_\-+=]{32,}(?![A-Za-z0-9])/', '[redacted]', $s);
        $s = trim((string) preg_replace('/\s+/', ' ', $s));
        return mb_strlen($s) > $max ? mb_substr($s, 0, $max - 1) . '…' : $s;
    }

    /**
     * Light cleaning for text we choose to keep as-is (SSH key comments like
     * worker@forge.laravel.com): control characters stripped, length capped.
     */
    public static function clean(string $s, int $max = 60): string
    {
        $s = trim((string) preg_replace('/[\x00-\x1F\x7F]+/u', ' ', $s));
        return mb_strlen($s) > $max ? mb_substr($s, 0, $max - 1) . '…' : $s;
    }

    /** A request path without its query string, scrubbed. */
    public function scrubPath(string $path, int $max = 200): string
    {
        $q = strcspn($path, '?#');
        return $this->scrub(substr($path, 0, $q), $max);
    }

    /** /home/forge/<site>/releases/<n>/x -> <release>/x ; /home/forge/<site>/x -> <site>/x */
    public static function shortenPaths(string $s): string
    {
        $s = (string) preg_replace('#/home/forge/[^/\s"\']+/releases/\d+/#', '<release>/', $s);
        return (string) preg_replace('#/home/forge/[^/\s"\']+/#', '<site>/', $s);
    }

    /**
     * Normalise a message into a stable signature, so "the same error" groups
     * together across days: numbers, hex, quoted strings and tokens collapse.
     */
    public static function signature(string $s): string
    {
        $s = self::shortenPaths($s);
        $s = (string) preg_replace('/ip:[0-9a-f]{12}/', 'ip:*', $s);
        $s = (string) preg_replace('/\b[0-9a-f]{8,}\b/i', '#', $s);
        $s = (string) preg_replace('/\d+/', '#', $s);
        $s = (string) preg_replace('/\s+/', ' ', $s);
        return substr(hash('sha256', trim($s)), 0, 12);
    }

    /** @return array<string,string> */
    public function ipMap(): array
    {
        return $this->map;
    }
}
