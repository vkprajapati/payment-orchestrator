<?php

namespace App\Http\Resources\Api\V1;

use App\Data\Audit\AuditHealthResult;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Public representation of audit subsystem health.
 *
 * Strict allow-list of global, aggregate operational values only. No
 * merchant identifiers, no event references, no metadata, no internal
 * exception details — the underlying DTO structurally carries none.
 *
 * @mixin AuditHealthResult
 */
class AuditHealthResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'healthy' => $this->healthy,
            'retention_config_valid' => $this->retentionConfigValid,
            'retention_days' => $this->retentionDays,
            'stale_events' => $this->staleCount,
            'newest_event_at' => $this->newestEventAt?->toISOString(),
            'checked_at' => $this->checkedAt->toISOString(),
            'reason' => $this->reason,
        ];
    }
}
