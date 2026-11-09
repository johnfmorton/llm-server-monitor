<?php

namespace App\Jobs;

use App\AlertSource;
use App\Models\Alert;
use App\Models\DailySummary;
use App\Models\Review;
use App\Monitoring\Review\Reviewer;
use App\Monitoring\Review\ReviewResult;
use App\Monitoring\Review\ReviewUnavailable;
use App\Monitoring\ReviewPrompt;
use App\Verdict;
use Illuminate\Contracts\Queue\ShouldBeUniqueUntilProcessing;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Has the model review one day's summary, stores the review, and raises an
 * alert if the verdict is "alert".
 *
 * The payload is read when the job runs, so if a day is sent again while its
 * review is still queued, the queued job reviews the newer copy and the
 * duplicate is dropped.
 */
class ReviewSummary implements ShouldBeUniqueUntilProcessing, ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public bool $deleteWhenMissingModels = true;

    public function __construct(public DailySummary $summary)
    {
        $this->timeout = (int) config('logwatch.review.timeout', 240) + 30;
    }

    public function uniqueId(): string
    {
        return (string) $this->summary->id;
    }

    /**
     * @return list<int>
     */
    public function backoff(): array
    {
        return [60, 600];
    }

    public function handle(Reviewer $reviewer, ReviewPrompt $prompt): void
    {
        $summary = $this->summary->loadMissing('server');

        try {
            $result = $reviewer->review(ReviewPrompt::SYSTEM, $prompt->build($summary));
        } catch (ReviewUnavailable $e) {
            $this->recordFailure($e->getMessage());

            return;
        }

        $alert = DB::transaction(fn (): ?Alert => $this->store($result));

        if ($alert !== null) {
            SendAlertEmail::dispatch($summary->server_id, $summary->date->toDateString());
        }
    }

    /**
     * After the last retry: keep a record, so the day page says why there's no verdict.
     */
    public function failed(?Throwable $exception): void
    {
        $this->recordFailure($exception?->getMessage() ?? 'The review failed.');
    }

    private function store(ReviewResult $result): ?Alert
    {
        $review = Review::create([
            'server_id' => $this->summary->server_id,
            'date' => $this->summary->date->toDateString(),
            'daily_summary_id' => $this->summary->id,
            'verdict' => $result->verdict,
            'confidence' => $result->confidence,
            'summary' => $result->summary,
            'findings' => $result->findings,
            'model' => $result->model,
            'input_tokens' => $result->inputTokens,
            'output_tokens' => $result->outputTokens,
        ]);

        if ($result->verdict !== Verdict::Alert) {
            return null;
        }

        return Alert::create([
            'server_id' => $review->server_id,
            'date' => $review->date->toDateString(),
            'source' => AlertSource::Review,
            'review_id' => $review->id,
            'title' => mb_strimwidth($this->alertTitle($result), 0, 180, '…'),
        ]);
    }

    private function alertTitle(ReviewResult $result): string
    {
        $findings = collect($result->findings);
        $lead = $findings->firstWhere('severity', 'high') ?? $findings->first();

        return $lead['title'] ?? ($result->summary !== '' ? $result->summary : 'The review flagged this day');
    }

    private function recordFailure(string $message): void
    {
        Review::create([
            'server_id' => $this->summary->server_id,
            'date' => $this->summary->date->toDateString(),
            'daily_summary_id' => $this->summary->id,
            'verdict' => null,
            'findings' => [],
            'error' => mb_strimwidth($message, 0, 1000, '…'),
            'model' => (string) config('logwatch.review.model'),
        ]);
    }
}
