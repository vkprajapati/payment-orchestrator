<?php

namespace App\Models;

use App\Enums\PaymentAttemptStatus;
use Database\Factories\PaymentAttemptFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A single processing attempt of a Payment through one provider.
 *
 * merchant_id and payment_id are intentionally NOT fillable: the attempt
 * is always created through the Payment relation (payment_id) by the
 * CreatePaymentAttempt action, which copies merchant_id from the payment
 * itself. Arbitrary external input can never set the tenant linkage.
 *
 * @mixin Builder
 */
#[Fillable(['provider', 'provider_payment_id', 'status', 'amount', 'currency', 'failure_code', 'failure_message', 'request_metadata', 'response_metadata', 'started_at', 'completed_at'])]
class PaymentAttempt extends Model
{
    /** @use HasFactory<PaymentAttemptFactory> */
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
            'status' => PaymentAttemptStatus::class,
            'request_metadata' => 'array',
            'response_metadata' => 'array',
            'started_at' => 'datetime',
            'completed_at' => 'datetime',
        ];
    }

    /**
     * Get the payment this attempt belongs to.
     */
    public function payment(): BelongsTo
    {
        return $this->belongsTo(Payment::class);
    }

    /**
     * Get the merchant that owns this attempt.
     *
     * Intentionally denormalized from Payment::merchant_id (the action
     * guarantees they match) to keep tenant-scoped queries index-friendly.
     */
    public function merchant(): BelongsTo
    {
        return $this->belongsTo(Merchant::class);
    }

    /**
     * Determine whether the attempt is awaiting processing.
     */
    public function isPending(): bool
    {
        return $this->status === PaymentAttemptStatus::Pending;
    }

    /**
     * Determine whether the attempt completed successfully.
     */
    public function isSuccessful(): bool
    {
        return $this->status->isSuccessful();
    }

    /**
     * Determine whether the attempt has reached a final state.
     */
    public function isTerminal(): bool
    {
        return $this->status->isTerminal();
    }
}
