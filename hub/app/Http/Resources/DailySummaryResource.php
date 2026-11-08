<?php

namespace App\Http\Resources;

use App\Models\DailySummary;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin DailySummary
 */
class DailySummaryResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'server' => $this->server->name,
            'date' => $this->date->toDateString(),
            'size_bytes' => $this->size_bytes,
            'received_at' => $this->received_at->toIso8601String(),
            'replaced' => ! $this->wasRecentlyCreated,
        ];
    }
}
