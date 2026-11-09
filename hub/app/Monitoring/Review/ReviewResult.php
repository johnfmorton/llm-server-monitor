<?php

namespace App\Monitoring\Review;

use App\Verdict;
use Illuminate\Support\Facades\Validator;

/**
 * A model's review, checked against the schema it was asked for.
 */
final readonly class ReviewResult
{
    /**
     * @param  list<array{title: string, severity: string, evidence: string}>  $findings
     */
    public function __construct(
        public Verdict $verdict,
        public float $confidence,
        public string $summary,
        public array $findings,
        public string $model,
        public int $inputTokens = 0,
        public int $outputTokens = 0,
    ) {}

    /**
     * Validate the model's JSON. Structured outputs should guarantee the shape,
     * but the result decides whether an email goes out, so check it anyway.
     *
     * @throws InvalidReview
     */
    public static function fromJson(string $json, string $model, int $inputTokens = 0, int $outputTokens = 0): self
    {
        $data = json_decode($json, true);
        if (! is_array($data)) {
            throw new InvalidReview('The review was not valid JSON.');
        }

        $validator = Validator::make($data, [
            'verdict' => ['required', 'string', 'in:ok,watch,alert'],
            'confidence' => ['required', 'numeric'],
            'summary' => ['present', 'string', 'max:2000'],
            'findings' => ['present', 'array', 'max:30'],
            'findings.*.title' => ['required', 'string', 'max:300'],
            'findings.*.severity' => ['required', 'string', 'in:info,low,medium,high'],
            'findings.*.evidence' => ['required', 'string', 'max:3000'],
        ]);
        if ($validator->fails()) {
            throw new InvalidReview('The review did not match the schema: '.$validator->errors()->first());
        }

        $findings = array_map(fn (array $finding): array => [
            'title' => $finding['title'],
            'severity' => $finding['severity'],
            'evidence' => $finding['evidence'],
        ], array_values($data['findings']));

        return new self(
            verdict: Verdict::from($data['verdict']),
            confidence: max(0.0, min(1.0, (float) $data['confidence'])),
            summary: $data['summary'],
            findings: $findings,
            model: $model,
            inputTokens: $inputTokens,
            outputTokens: $outputTokens,
        );
    }
}
