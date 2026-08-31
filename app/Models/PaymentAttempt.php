<?php

namespace App\Models;

use App\Enums\PaymentAttemptStatus;
use Database\Factories\PaymentAttemptFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

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
     * Refunds associated with this attempt — i.e. refunds whose money is
     * coming back through this provider pass.
     */
    public function refunds(): HasMany
    {
        return $this->hasMany(Refund::class);
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

    /**
     * Determine whether the attempt is eligible for execution.
     *
     * Only pending attempts can be processed; terminal states
     * (succeeded, failed, cancelled) are rejected to prevent duplicate
     * charges.
     */
    public function canProcess(): bool
    {
        return $this->status === PaymentAttemptStatus::Pending;
    }

    /**
     * Transition the attempt to the processing state.
     *
     * Marks the attempt as started. Called inside the first transaction
     * before the provider call.
     */
    public function markProcessing(): void
    {
        $this->status = PaymentAttemptStatus::Processing;
        $this->started_at = now();
        $this->save();
    }

    /**
     * Mark the attempt as succeeded with the provider's payment id.
     *
     * Persists the provider reference and response metadata, then sets
     * the completed timestamp. Called inside the second transaction after
     * a successful provider response.
     */
    public function markSucceeded(?string $providerPaymentId, array $responseMetadata = []): void
    {
        $this->provider_payment_id = $providerPaymentId;
        $this->response_metadata = $responseMetadata;
        $this->status = PaymentAttemptStatus::Succeeded;
        $this->completed_at = now();
        $this->save();
    }

    /**
     * Mark the attempt as failed.
     *
     * Persists failure details (code, message, response metadata) and
     * the completed timestamp. Called inside the second transaction after
     * a failed provider response.
     */
    public function markFailed(?string $providerPaymentId, ?string $failureCode, ?string $failureMessage, array $responseMetadata = []): void
    {
        $this->provider_payment_id = $providerPaymentId;
        $this->failure_code = $failureCode;
        $this->failure_message = $failureMessage;
        $this->response_metadata = $responseMetadata;
        $this->status = PaymentAttemptStatus::Failed;
        $this->completed_at = now();
        $this->save();
    }

    /**
     * Mark the attempt as cancelled.
     *
     * Used when an attempt is abandoned without provider interaction
     * (e.g. superseded by a newer attempt). Cancelled attempts are
     * terminal and cannot be re-executed.
     */
    public function markCancelled(): void
    {
        $this->status = PaymentAttemptStatus::Cancelled;
        $this->completed_at = now();
        $this->save();
    }
}
