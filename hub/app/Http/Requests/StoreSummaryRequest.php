<?php

namespace App\Http\Requests;

use App\Models\DailySummary;
use App\Models\Server;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class StoreSummaryRequest extends FormRequest
{
    /** Largest summary accepted. Real ones are about 20 KB. */
    public const MAX_BYTES = 1048576;

    /**
     * The AuthenticateServer middleware has already matched the token to a server.
     */
    public function authorize(): bool
    {
        return $this->attributes->get('server') instanceof Server;
    }

    /**
     * Only the envelope is validated. The sections are the collector's business,
     * and the review step reads them defensively.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'schema' => ['required', 'integer', 'in:1'],
            'date' => [
                'required',
                'date_format:Y-m-d',
                'before_or_equal:today',
                'after_or_equal:'.today()->subDays(DailySummary::RETENTION_DAYS)->toDateString(),
            ],
            'collector_version' => ['required', 'string', 'max:20'],
            'server' => ['nullable', 'string', 'max:255'],
        ];
    }

    public function sendingServer(): Server
    {
        return $this->attributes->get('server');
    }
}
