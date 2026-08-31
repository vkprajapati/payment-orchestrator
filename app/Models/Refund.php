<?php

namespace App\Models;

use App\Enums\RefundStatus;
use Database\Factories\RefundFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A (possibly partial) refund of a succeeded Payment.
 *
 * merchant_id and payment_id are intentionally NOT fillable: the refund is
 * always created through the Payment relation (payment_id) by the
 * CreateRefund action, which copies merchant_id from the payment itself.
 * Arbitrary external input can never set the tenant linkage.
 *
 * A refund starts in the pending state and NEVER mutates the parent
 * payment's status on creation — payments become partially_refunded /
 * refunded only later, during refund execution and reconciliation.
 *
 * @mixin Builder
 */
#[Fillable(['reference', 'payment_attempt_id', 'provider', 'provider_refund_id', 'amount', 'currency', 'status', 'reason', 'failure_code', 'failure_message', 'request_metadata', 'response_metadata', 'requested_at', 'completed_at'])]
class Refund extends Model
{
    /** @use HasFactory<RefundFactory> */
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
            'status' => RefundStatus::class,
            'request_metadata' => 'array',
            'response_metadata' => 'array',
            'requested_at' => 'datetime',
            'completed_at' => 'datetime',
        ];
    }

    /**
     * Get the payment this refund belongs to.
     */
    public function payment(): BelongsTo
    {
        return $this->belongsTo(Payment::class);
    }

    /**
     * Get the optional payment attempt this refund is tied to.
     */
    public function paymentAttempt(): BelongsTo
    {
        return $this->belongsTo(PaymentAttempt::class);
    }

    /**
     * Get the merchant that owns this refund.
     *
     * Intentionally denormalized from Payment::merchant_id (the action
     * guarantees they match) to keep tenant-scoped queries index-friendly.
     */
    public function merchant(): BelongsTo
    {
        return $this->belongsTo(Merchant::class);
    }

    /**
     * Determine whether the refund has reached a final state.
     */
    public function isTerminal(): bool
    {
        return $this->status->isTerminal();
    }

    /**
     * Determine whether the refund completed successfully at the provider.
     */
    public function isSuccessful(): bool
    {
        return $this->status->isSuccessful();
    }
}
