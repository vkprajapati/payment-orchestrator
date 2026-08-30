<?php

namespace App\Http\Resources\Api\V1;

use App\Models\PaymentAttempt;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Public representation of a payment attempt.
 *
 * Deliberately excludes the internal id, merchant_id, payment_id, and
 * metadata (request/response metadata may later contain provider
 * details). The payment is identified by its public reference only.
 *
 * @mixin PaymentAttempt
 */
class PaymentAttemptResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'payment_reference' => $this->payment->reference,
            'provider' => $this->provider,
            'provider_payment_id' => $this->provider_payment_id,
            'status' => $this->status->value,
            'amount' => $this->amount,
            'currency' => $this->currency,
            'failure_code' => $this->failure_code,
            'failure_message' => $this->failure_message,
            'created_at' => $this->created_at->toISOString(),
            'started_at' => $this->started_at?->toISOString(),
            'completed_at' => $this->completed_at?->toISOString(),
        ];
    }
}
