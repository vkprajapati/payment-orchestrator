<?php

namespace App\Actions\Payments;

use App\Enums\PaymentProviderName;
use App\Enums\PaymentStatus;
use App\Enums\RefundStatus;
use App\Models\Payment;
use App\Models\PaymentAttempt;
use App\Models\Refund;
use Illuminate\Support\Str;
use InvalidArgumentException;

class CreateRefund
{
    /**
     * Create a pending refund for a refundable payment.
     *
     * Domain-level validation and persistence only — no provider calls
     * happen here; provider refund execution belongs to a later step.
     *
     * Security: merchant identity ALWAYS comes from the payment itself,
     * never from $data. A "merchant_id" key in $data is ignored because
     * merchant_id is not fillable on the model and the refund is created
     * through the payment's relation.
     *
     * Reservation model: pending, processing and succeeded refunds consume
     * the payment's refundable balance; failed and cancelled refunds do
     * not. This prevents multiple simultaneous refund requests from
     * exceeding the original payment amount.
     *
     * Concurrency note: the remaining-balance calculation here is
     * application-level and is INSUFFICIENT on its own for concurrent
     * refund creation — two requests can read the same balance and both
     * pass validation. The future refund API/execution step MUST wrap
     * this call in a database transaction that first takes lockForUpdate()
     * on the payment row and re-reads the payment and its refunds inside
     * that transaction. The domain logic below is deliberately kept
     * transaction-agnostic so that wrapper can be added without rewriting.
     *
     * @param  array{amount?: mixed, currency?: string|null, payment_attempt_id?: int|null, provider?: string|null, reason?: string|null, request_metadata?: array<string, mixed>|null}  $data
     *
     * @throws InvalidArgumentException when the amount is not a positive
     *                                  integer, exceeds the remaining refundable balance, the currency
     *                                  is invalid or mismatched, the payment is not refundable, the
     *                                  provider is unknown, or the attempt association is invalid
     */
    public function create(Payment $payment, array $data): Refund
    {
        // -----------------------------------------------------------------
        // Amount: only a real integer is accepted. is_int() deliberately
        // rejects floats, numeric strings, booleans and null — "1000" and
        // 10.50 are never valid smallest-unit amounts.
        // -----------------------------------------------------------------
        $amount = $data['amount'] ?? null;

        if (! is_int($amount) || $amount <= 0) {
            throw new InvalidArgumentException('The refund amount must be a positive integer in the smallest currency unit (e.g. 1050 = $10.50).');
        }

        // -----------------------------------------------------------------
        // Currency: defaults to the payment currency, otherwise normalized
        // to uppercase and required to match the payment — no cross-currency
        // refunds.
        // -----------------------------------------------------------------
        $currency = Str::upper(trim((string) ($data['currency'] ?? $payment->currency)));

        if (! preg_match('/^[A-Z]{3}$/', $currency)) {
            throw new InvalidArgumentException('The refund currency must be a 3-letter ISO 4217 code.');
        }

        if ($currency !== $payment->currency) {
            throw new InvalidArgumentException('The refund currency must match the payment currency; cross-currency refunds are not supported.');
        }

        return $this->persistValidated($payment, $data, $amount, $currency);
    }

    /**
     * Run the remaining validations and persist the pending refund.
     *
     * Kept separate from create() so a future transaction wrapper (with a
     * payment row lock for true concurrent over-refund protection) can be
     * added around the whole operation without rewriting the domain logic.
     *
     * @param  array{payment_attempt_id?: int|null, provider?: string|null, reason?: string|null, request_metadata?: array<string, mixed>|null}  $data
     */
    private function persistValidated(Payment $payment, array $data, int $amount, string $currency): Refund
    {
        // -----------------------------------------------------------------
        // Payment eligibility: only succeeded and partially_refunded
        // payments can be refunded.
        // -----------------------------------------------------------------
        if (! in_array($payment->status, [PaymentStatus::Succeeded, PaymentStatus::PartiallyRefunded], true)) {
            throw new InvalidArgumentException(sprintf('A payment with status "%s" cannot be refunded.', $payment->status->value));
        }

        // -----------------------------------------------------------------
        // Over-refund protection: the amount may never exceed the balance
        // not already reserved by pending/processing/succeeded refunds.
        // -----------------------------------------------------------------
        if ($amount > $payment->remainingRefundableAmount()) {
            throw new InvalidArgumentException('The refund amount exceeds the remaining refundable balance of the payment.');
        }

        // -----------------------------------------------------------------
        // Provider (optional): normalized and validated, but never executed.
        // -----------------------------------------------------------------
        $provider = null;

        if (array_key_exists('provider', $data) && $data['provider'] !== null) {
            $provider = PaymentProviderName::normalize((string) $data['provider']);

            if (! PaymentProviderName::isValid($provider)) {
                throw new InvalidArgumentException(sprintf('The provider "%s" is not a known payment provider.', $data['provider']));
            }
        }

        // -----------------------------------------------------------------
        // Attempt association (optional): the attempt must belong to this
        // payment AND to the same merchant. Invalid associations are
        // rejected, never silently accepted.
        // -----------------------------------------------------------------
        $attempt = null;

        if (array_key_exists('payment_attempt_id', $data) && $data['payment_attempt_id'] !== null) {
            $attemptId = $data['payment_attempt_id'];

            if (! is_int($attemptId)) {
                throw new InvalidArgumentException('The payment attempt id must be an integer.');
            }

            $attempt = $payment->attempts()->find($attemptId);

            if (! $attempt instanceof PaymentAttempt || $attempt->merchant_id !== $payment->merchant_id) {
                throw new InvalidArgumentException('The given payment attempt does not belong to this payment.');
            }
        }

        // -----------------------------------------------------------------
        // Persistence: created through the payment's relation (payment_id),
        // with the merchant copied from the payment — never from $data.
        // Every refund starts pending and the parent payment's status is
        // intentionally left untouched.
        // -----------------------------------------------------------------
        $refund = $payment->refunds()->make([
            'reference' => self::generateReference(),
            'payment_attempt_id' => $attempt?->id,
            'provider' => $provider,
            'amount' => $amount,
            'currency' => $currency,
            'status' => RefundStatus::Pending,
            'reason' => $data['reason'] ?? null,
            'request_metadata' => $data['request_metadata'] ?? null,
            'requested_at' => now(),
        ]);
        $refund->merchant_id = $payment->merchant_id;
        $refund->save();

        return $refund;
    }

    /**
     * Generate a unique, URL-safe, publicly exposable refund reference.
     */
    public static function generateReference(): string
    {
        return 'ref_'.Str::ulid();
    }
}
