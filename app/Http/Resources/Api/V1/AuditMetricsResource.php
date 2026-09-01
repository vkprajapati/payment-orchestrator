<?php

namespace App\Http\Resources\Api\V1;

use App\Data\Audit\AuditMetricsResult;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Public representation of merchant audit metrics.
 *
 * Strict allow-list of aggregate values only: totals, groupings, and a
 * time range. Individual audit events, internal ids, merchant identity,
 * metadata, and request/response internals are structurally impossible to
 * include — the underlying DTO carries none of them.
 *
 * @mixin AuditMetricsResult
 */
class AuditMetricsResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'total' => $this->total,
            'by_event' => $this->byEvent,
            'by_outcome' => $this->byOutcome,
            'time_range' => [
                'from' => $this->from?->toISOString(),
                'to' => $this->to?->toISOString(),
            ],
        ];
    }
}
