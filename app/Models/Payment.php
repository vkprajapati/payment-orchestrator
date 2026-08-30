<?php

namespace App\Models;

use App\Enums\PaymentStatus;
use Database\Factories\PaymentFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A provider-agnostic payment owned by exactly one merchant.
 *
 * merchant_id is intentionally NOT fillable: the owning merchant must
 * always be resolved server-side (via $merchant->payments()->create()
 * or the CreatePayment action), never from request input.
 */
#[Fillable(['reference', 'idempotency_key', 'amount', 'currency', 'status', 'description', 'metadata'])]
class Payment extends Model
{
    /** @use HasFactory<PaymentFactory> */
    use HasFactory;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'amount' => 'integer',
            'status' => PaymentStatus::class,
            'metadata' => 'array',
        ];
    }

    /**
     * Get the merchant that owns the payment.
     */
    public function merchant(): BelongsTo
    {
        return $this->belongsTo(Merchant::class);
    }

    /**
     * Determine whether the payment is awaiting processing.
     */
    public function isPending(): bool
    {
        return $this->status === PaymentStatus::Pending;
    }

    /**
     * Determine whether the payment is being processed by a provider.
     */
    public function isProcessing(): bool
    {
        return $this->status === PaymentStatus::Processing;
    }

    /**
     * Determine whether the payment completed successfully.
     */
    public function isSucceeded(): bool
    {
        return $this->status === PaymentStatus::Succeeded;
    }

    /**
     * Determine whether the payment failed.
     */
    public function isFailed(): bool
    {
        return $this->status === PaymentStatus::Failed;
    }

    /**
     * Determine whether the payment has reached a final state.
     */
    public function isTerminal(): bool
    {
        return $this->status?->isTerminal() ?? false;
    }
}
