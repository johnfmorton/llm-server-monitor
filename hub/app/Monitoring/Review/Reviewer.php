<?php

namespace App\Monitoring\Review;

/**
 * Sends one review request to a model and returns its validated verdict.
 */
interface Reviewer
{
    /**
     * @throws ReviewUnavailable when the model refuses or the API rejects the request; retrying won't help
     * @throws InvalidReview when the response can't be used; a retry might
     */
    public function review(string $system, string $prompt): ReviewResult;
}
