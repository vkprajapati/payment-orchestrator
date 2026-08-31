<?php

namespace App\Actions\Payments;

use App\Contracts\Payments\PaymentProvider;
use App\Contracts\Payments\RefundProvider;
use App\Data\Payments\PaymentProviderResult;
use App\Enums\PaymentAttemptStatus;
use App\Enums\PaymentStatus;
use App\Enums\RefundStatus;
use App\Exceptions\PaymentProviderException;
use App\Exceptions\RefundNotProcessableException;
use App\Models\Payment;
use App\Models\PaymentAttempt;
use App\Models\Refund;
use App\Services\Payments\PaymentProviderManager;
use Illuminate\Support\Facades\DB;
use Throwable;

class ProcessRefund
{
    /**
     * Generic message persisted/returned when a provider throws. The
     * underlying exception message may contain credentials or internal
     * details and is never exposed — it is only reported to the log.
     */
    private const PROVIDER_FAILURE_MESSAGE = 'Provider refund failed.';

    public function __construct(private readonly PaymentProviderManager $manager) {}

    /**
     * Execute a pending refund through the provider that successfully
     * processed the original payment.
     *
     * Transaction design (mirrors ProcessPaymentAttempt):
     *
     *   Transaction 1 — lock the refund row, re-verify it is still pending
     *   (terminal refunds can never execute twice), resolve the refund
     *   provider from the original successful payment attempt, verify the
     *   provider actually supports the refund operation, mark the refund
     *   processing, then COMMIT before contacting the provider. No
     *   long-lived locks are held during the external HTTP call.
     *
     *   Provider call — runs OUTSIDE any transaction.
     *
     *   Transaction 2 — lock the refund again and atomically persist the
     *   provider result, updating the parent payment's refund status only
     *   on success (pending/processing refunds never mark the payment
     *   refunded; a failed refund never changes the payment).
     *
     * @throws RefundNotProcessableException when the refund is not pending
     *                                       or no refund-capable provider can be determined
     * @throws PaymentProviderException when the resolved provider does not
     *                                  support the refund operation
     */
    public function process(Refund $refund): Refund
    {
        [$refundId, $provider, $payment, $attempt] = DB::transaction(function () use ($refund) {
            $refund = Refund::query()->lockForUpdate()->findOrFail($refund->id);

            if ($refund->status !== RefundStatus::Pending) {
                throw RefundNotProcessableException::forStatus($refund->status);
            }

            $payment = $refund->payment()->lockForUpdate()->firstOrFail();

            $attempt = $this->resolveAttempt($refund, $payment);

            $provider = $this->manager->resolve($attempt->getRawOriginal('provider'));

            // Capability gate: a charge-capable provider is NOT
            // automatically refund-capable.
            if (! $provider instanceof RefundProvider
                || ! $provider->supports(PaymentProvider::OPERATION_REFUND)) {
                throw PaymentProviderException::unsupportedOperation($provider->name(), PaymentProvider::OPERATION_REFUND);
            }

            $refund->provider = $provider->name();
            $refund->payment_attempt_id ??= $attempt->id;
            $refund->status = RefundStatus::Processing;
            $refund->save();

            return [$refund->id, $provider, $payment, $attempt];
        });

        try {
            $result = $provider->refund($payment, $attempt, $refund->refresh());
        } catch (Throwable $exception) {
            report($exception);

            $result = new PaymentProviderResult(
                success: false,
                provider: $provider->name(),
                providerPaymentId: null,
                status: RefundStatus::Failed->value,
                message: self::PROVIDER_FAILURE_MESSAGE,
                failureCode: 'provider_exception',
                metadata: [],
            );
        }

        return $this->persistResult($refundId, $result);
    }

    /**
     * Deterministic provider selection: refunds route back through the
     * provider that successfully processed the original payment — NEVER
     * through the charge failover strategy. A Stripe payment is refunded
     * through Stripe, not through PayU or Mock.
     *
     * Preference order:
     *   1. the attempt explicitly associated with the refund (validated to
     *      belong to this payment at creation time) — but only when it
     *      actually succeeded;
     *   2. otherwise the latest successful attempt of the payment.
     *
     * Failed attempts are never selected. When no successful attempt
     * exists, a controlled domain error is thrown — no alternative
     * provider is silently chosen.
     *
     * @throws RefundNotProcessableException when no successful attempt exists
     */
    private function resolveAttempt(Refund $refund, Payment $payment): PaymentAttempt
    {
        if ($refund->payment_attempt_id !== null) {
            $attempt = $payment->attempts()->find($refund->payment_attempt_id);

            if ($attempt !== null && $attempt->status === PaymentAttemptStatus::Succeeded) {
                return $attempt;
            }
        }

        $attempt = $payment->attempts()
            ->where('status', PaymentAttemptStatus::Succeeded->value)
            ->orderByDesc('id')
            ->first();

        if ($attempt === null) {
            throw RefundNotProcessableException::noProvider();
        }

        return $attempt;
    }

    /**
     * Atomically persist the provider outcome to the refund and update the
     * parent payment's refund lifecycle status on success.
     */
    private function persistResult(int $refundId, PaymentProviderResult $result): Refund
    {
        return DB::transaction(function () use ($refundId, $result) {
            $refund = Refund::query()->lockForUpdate()->findOrFail($refundId);

            // Defensive guard: another process may have finalized this
            // refund while the provider call was in flight.
            if ($refund->status !== RefundStatus::Processing) {
                return $refund->refresh();
            }

            if ($result->success) {
                $refund->provider_refund_id = $result->providerPaymentId;
                $refund->response_metadata = $result->metadata;
                $refund->failure_code = null;
                $refund->failure_message = null;
                $refund->status = RefundStatus::Succeeded;
                $refund->completed_at = now();
                $refund->save();

                $this->updatePaymentRefundStatus(
                    $refund->payment()->lockForUpdate()->firstOrFail(),
                );
            } else {
                $refund->failure_code = $result->failureCode;
                $refund->failure_message = $result->message;
                $refund->response_metadata = $result->metadata;
                $refund->status = RefundStatus::Failed;
                $refund->completed_at = now();
                $refund->save();
            }

            return $refund->refresh();
        });
    }

    /**
     * Move the parent payment through the refund lifecycle:
     *
     *   succeeded → partially_refunded  (first successful partial refund)
     *   → refunded                      (successful refunds cover the amount)
     *
     * A failed refund never changes the payment, and pending/processing
     * refunds never mark it refunded. An already refunded payment is never
     * downgraded.
     */
    private function updatePaymentRefundStatus(Payment $payment): void
    {
        $refunded = $payment->totalSuccessfulRefundAmount();

        if ($refunded <= 0 || $payment->status === PaymentStatus::Refunded) {
            return;
        }

        $status = $refunded >= $payment->amount
            ? PaymentStatus::Refunded
            : PaymentStatus::PartiallyRefunded;

        if ($payment->status !== $status) {
            $payment->status = $status;
            $payment->save();
        }
    }
}
