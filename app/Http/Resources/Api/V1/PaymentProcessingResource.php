<?php

namespace App\Http\Resources\Api\V1;

use App\Models\PaymentAttempt;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Processing response: the updated payment plus the executed attempt.
 *
 * Exposes no internal IDs, merchant linkage, idempotency keys, or raw
 * provider metadata beyond the safe attempt fields below.
 *
 * @mixin PaymentAttempt
 */
class PaymentProcessingResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'payment' => new PaymentResource($this->payment),
            'attempt' => [
                'provider' => $this->getRawOriginal('provider'),
                'provider_payment_id' => $this->provider_payment_id,
                'status' => $this->status->value,
                'amount' => $this->amount,
                'currency' => $this->currency,
                'started_at' => $this->started_at?->toISOString(),
                'completed_at' => $this->completed_at?->toISOString(),
            ],
        ];
    }
}
