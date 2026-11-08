<?php

declare(strict_types=1);

namespace LogWatch;

/**
 * Sends summaries to the hub. Anything that can't be delivered waits in an
 * outbox and is retried on the next run, so a hub outage doesn't lose days.
 */
final class Poster
{
    /** The hub rejects days older than its 30-day retention, so don't keep them longer. */
    private const OUTBOX_DAYS = 30;

    /**
     * @param list<int> $backoff seconds to wait before each retry
     */
    public function __construct(
        private readonly string $url,
        private readonly string $token,
        /** null: don't keep failed sends (trial runs with --no-state) */
        private readonly ?string $outboxDir,
        private readonly array $backoff = [5, 30],
        private readonly int $timeout = 30,
    ) {
    }

    /**
     * Send one day's summary; on failure, keep it in the outbox.
     * @return array{ok:bool, status:int, message:string}
     */
    public function send(string $date, string $json): array
    {
        $result = $this->deliver($json);
        if ($this->outboxDir !== null) {
            if ($result['ok']) {
                @unlink($this->outboxPath($date));
            } else {
                $this->keep($date, $json);
            }
        }
        return $result;
    }

    /**
     * Retry everything waiting in the outbox, oldest first.
     * @return list<array{date:string, ok:bool, status:int, message:string}>
     */
    public function flushOutbox(string $today): array
    {
        $results = [];
        if ($this->outboxDir === null) {
            return $results;
        }
        $cutoff = gmdate('Y-m-d', strtotime("$today UTC") - self::OUTBOX_DAYS * 86400);
        $files = glob("{$this->outboxDir}/*.json") ?: [];
        sort($files);
        foreach ($files as $file) {
            $date = basename($file, '.json');
            if ($date < $cutoff) {
                @unlink($file);
                continue;
            }
            $json = SafeFile::read($file, 4 * 1048576);
            if ($json === null) {
                continue;
            }
            $r = $this->deliver($json);
            if ($r['ok']) {
                @unlink($file);
            }
            $results[] = ['date' => $date] + $r;
        }
        return $results;
    }

    /** @return array{ok:bool, status:int, message:string} */
    private function deliver(string $json): array
    {
        $last = ['ok' => false, 'status' => 0, 'message' => 'not attempted'];
        $delays = [0, ...$this->backoff];
        foreach ($delays as $i => $delay) {
            if ($delay > 0) {
                sleep($delay);
            }
            $last = $this->attempt($json);
            // Retry only what might succeed later: no response, server errors, rate limiting.
            $retryable = $last['status'] === 0 || $last['status'] >= 500 || $last['status'] === 429;
            if ($last['ok'] || !$retryable) {
                break;
            }
        }
        return $last;
    }

    /** @return array{ok:bool, status:int, message:string} */
    private function attempt(string $json): array
    {
        $context = stream_context_create([
            'http' => [
                'method' => 'POST',
                'header' => implode("\r\n", [
                    'Authorization: Bearer ' . $this->token,
                    'Content-Type: application/json',
                    'Accept: application/json',
                    'User-Agent: logwatch-collector/' . Collector::VERSION,
                ]),
                'content' => $json,
                'timeout' => $this->timeout,
                'ignore_errors' => true,
                'follow_location' => 0,
            ],
            'ssl' => ['verify_peer' => true, 'verify_peer_name' => true],
        ]);
        $body = @file_get_contents($this->url, false, $context);
        $headers = function_exists('http_get_last_response_headers') ? (http_get_last_response_headers() ?? []) : ($http_response_header ?? []);
        $status = isset($headers[0]) && preg_match('#^HTTP/\S+ (\d{3})#', $headers[0], $m) ? (int) $m[1] : 0;
        if ($status === 0) {
            $err = error_get_last();
            return ['ok' => false, 'status' => 0, 'message' => 'no response: ' . ($err['message'] ?? 'unknown error')];
        }
        $message = '';
        $decoded = json_decode((string) $body, true);
        if (is_array($decoded)) {
            $message = (string) ($decoded['message'] ?? ($decoded['data']['received_at'] ?? ''));
        }
        return ['ok' => $status >= 200 && $status < 300, 'status' => $status, 'message' => mb_substr($message, 0, 200)];
    }

    private function keep(string $date, string $json): void
    {
        if (!is_dir($this->outboxDir)) {
            @mkdir($this->outboxDir, 0700, true);
        }
        $path = $this->outboxPath($date);
        @file_put_contents("$path.tmp", $json);
        @chmod("$path.tmp", 0600);
        @rename("$path.tmp", $path);
    }

    private function outboxPath(string $date): string
    {
        return "{$this->outboxDir}/$date.json";
    }
}
