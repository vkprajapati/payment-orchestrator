<?php

namespace App\Http\Resources\Api\V1;

use App\Models\AuditEvent;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Public representation of an audit event.
 *
 * Strict allow-list: the numeric internal id, merchant_id, and all raw
 * request/response internals are deliberately excluded. The metadata column
 * is filtered at the read boundary against the SAME explicit allow-list the
 * AuditLogger enforces at write time — defense in depth, so even a
 * non-whitelisted key that somehow reaches the database is never serialized.
 *
 * @mixin AuditEvent
 */
class AuditEventResource extends JsonResource
{
    /**
     * Metadata keys safe to expose. Must stay in sync with the write-time
     * whitelist in App\Services\Audit\AuditLogger.
     *
     * @var list<string>
     */
    private const SAFE_METADATA = ['amount', 'currency', 'provider', 'status', 'reason'];

    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $metadata = $this->metadata;

        return [
            'reference' => $this->reference,
            'event' => $this->event->value,
            'outcome' => $this->outcome?->value,
            'http_method' => $this->http_method,
            'path' => $this->path,
            'response_status' => $this->response_status,
            'payment_reference' => $this->payment_reference,
            'refund_reference' => $this->refund_reference,
            'idempotency_replayed' => $this->idempotency_replayed,
            'metadata' => $metadata === null
                ? null
                : array_intersect_key($metadata, array_flip(self::SAFE_METADATA)),
            'performed_at' => $this->performed_at?->toISOString(),
            'created_at' => $this->created_at?->toISOString(),
        ];
    }
}
