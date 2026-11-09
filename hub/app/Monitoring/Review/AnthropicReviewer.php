<?php

namespace App\Monitoring\Review;

use Anthropic\Beta\AnthropicBeta;
use Anthropic\Beta\Messages\BetaMessage;
use Anthropic\Client;
use Anthropic\Core\Exceptions\APIStatusException;
use App\Monitoring\ReviewPrompt;

/**
 * Reviews with Claude through the Messages API, using structured outputs so
 * the answer is JSON in the expected shape.
 *
 * Server-side fallback is on: if a safety classifier declines the request
 * (security logs are full of attack traffic, which can trip one), the API
 * retries on Anthropic's recommended fallback model and reports which model
 * answered.
 */
class AnthropicReviewer implements Reviewer
{
    public function __construct(
        private Client $client,
        private string $model,
        private string $effort,
        private int $maxTokens,
    ) {}

    public function review(string $system, string $prompt): ReviewResult
    {
        try {
            return $this->parse($this->send($system, $prompt));
        } catch (APIStatusException $e) {
            // Timeouts, conflicts, rate limits and server errors may pass on a retry; anything else won't.
            if ($e->status !== null && $e->status < 500 && ! in_array($e->status, [408, 409, 429], true)) {
                throw new ReviewUnavailable("The API rejected the review request (HTTP {$e->status}): ".($e->getAPIMessage() ?? $e->getMessage()), previous: $e);
            }
            throw $e;
        }
    }

    private function send(string $system, string $prompt): BetaMessage
    {
        return $this->client->beta->messages->create(
            model: $this->model,
            maxTokens: $this->maxTokens,
            system: $system,
            messages: [['role' => 'user', 'content' => $prompt]],
            outputConfig: [
                'effort' => $this->effort,
                'format' => ['type' => 'json_schema', 'schema' => ReviewPrompt::schema()],
            ],
            fallbacks: 'default',
            betas: [AnthropicBeta::SERVER_SIDE_FALLBACK_2026_07_01],
        );
    }

    private function parse(BetaMessage $message): ReviewResult
    {
        if ($message->stopReason === 'refusal') {
            $category = $message->stopDetails?->category;
            throw new ReviewDeclined('The model declined to review this day'.($category ? " ({$category})" : '').'.');
        }
        if ($message->stopReason !== 'end_turn') {
            throw new InvalidReview("The review stopped early ({$message->stopReason}).");
        }

        $text = '';
        foreach ($message->content as $block) {
            if ($block->type === 'text') {
                $text .= $block->text;
            }
        }

        return ReviewResult::fromJson(
            $text,
            model: (string) $message->model,
            inputTokens: $message->usage->inputTokens + (int) $message->usage->cacheReadInputTokens + (int) $message->usage->cacheCreationInputTokens,
            outputTokens: $message->usage->outputTokens,
        );
    }
}
