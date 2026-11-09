<?php

namespace Tests\Fakes;

use App\Monitoring\Review\Reviewer;
use App\Monitoring\Review\ReviewResult;
use App\Verdict;
use Throwable;

/**
 * Stands in for the model in tests: returns a fixed review (or throws) and
 * remembers the prompts it was given.
 */
class FakeReviewer implements Reviewer
{
    /** @var list<string> */
    public array $prompts = [];

    public function __construct(private ReviewResult|Throwable|null $response = null) {}

    public static function ok(): self
    {
        return new self(new ReviewResult(Verdict::Ok, 0.9, 'An ordinary day.', [], 'claude-sonnet-5-5', 6000, 400));
    }

    public function review(string $system, string $prompt): ReviewResult
    {
        $this->prompts[] = $prompt;

        if ($this->response instanceof Throwable) {
            throw $this->response;
        }

        return $this->response ?? self::ok()->response;
    }
}
