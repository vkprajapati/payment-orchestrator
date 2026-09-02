<?php

namespace App\Models;

use App\Enums\PaymentStatus;
use App\Enums\RefundStatus;
use Database\Factories\PaymentFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

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
     * Bind route models by the public reference, never the internal ID.
     *
     * Web URLs therefore read /payments/pay_XXXX and route() generation
     * emits references automatically; the numeric surrogate key never
     * appears in a URL.
     */
    public function getRouteKeyName(): string
    {
        return 'reference';
    }

    /**
     * Get the processing attempts made for this payment.
     *
     * A payment may be tried through several providers over its lifetime;
     * no default ordering is imposed — callers sort explicitly.
     */
    public function attempts(): HasMany
    {
        return $this->hasMany(PaymentAttempt::class);
    }

    /**
     * The refunds issued against this payment.
     */
    public function refunds(): HasMany
    {
        return $this->hasMany(Refund::class);
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
     * Total amount currently reserved against this payment's refundable
     * balance.
     *
     * Pending, processing and succeeded refunds consume the balance;
     * failed and cancelled refunds release it. This single efficient
     * aggregate query is the basis of over-refund protection.
     */
    public function totalRefundedAmount(): int
    {
        return (int) $this->refunds()
            ->whereIn('status', RefundStatus::balanceReservingValues())
            ->sum('amount');
    }

    /**
     * Total amount of refunds that actually completed at a provider.
     */
    public function totalSuccessfulRefundAmount(): int
    {
        return (int) $this->refunds()
            ->where('status', RefundStatus::Succeeded->value)
            ->sum('amount');
    }

    /**
     * The amount still available for new refunds.
     */
    public function remainingRefundableAmount(): int
    {
        return max(0, $this->amount - $this->totalRefundedAmount());
    }

    /**
     * Determine whether any refund has been requested for this payment.
     */
    public function hasRefunds(): bool
    {
        return $this->refunds()->exists();
    }

    /**
     * Determine whether the payment has reached a final state.
     */
    public function isTerminal(): bool
    {
        return $this->status?->isTerminal() ?? false;
    }
}
