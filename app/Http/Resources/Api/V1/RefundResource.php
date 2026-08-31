<?php

namespace App\Http\Resources\Api\V1;

use App\Models\Refund;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Public representation of a refund.
 *
 * Deliberately excludes the internal id, merchant_id, payment_id, and
 * payment_attempt_id: the surrogate key and all tenant linkages are
 * internal. Request/response metadata is also excluded — it may contain
 * provider details that must never be exposed. The payment is identified
 * by its public reference only, and the status is serialized as its plain
 * string value.
 *
 * @mixin Refund
 */
class RefundResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'reference' => $this->reference,
            'payment_reference' => $this->payment->reference,
            'provider' => $this->provider,
            'provider_refund_id' => $this->provider_refund_id,
            'amount' => $this->amount,
            'currency' => $this->currency,
            'status' => $this->status->value,
            'reason' => $this->reason,
            'failure_code' => $this->failure_code,
            'failure_message' => $this->failure_message,
            'requested_at' => $this->requested_at?->toISOString(),
            'completed_at' => $this->completed_at?->toISOString(),
            'created_at' => $this->created_at->toISOString(),
            'updated_at' => $this->updated_at->toISOString(),
        ];
    }
}
