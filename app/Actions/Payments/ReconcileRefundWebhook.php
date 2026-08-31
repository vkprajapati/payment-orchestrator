<?php

namespace App\Actions\Payments;

use App\Data\Payments\PaymentProviderWebhookResult;
use App\Data\Payments\RefundWebhookReconciliationResult;
use App\Enums\PaymentStatus;
use App\Enums\RefundStatus;
use App\Models\Payment;
use App\Models\Refund;
use Illuminate\Support\Facades\DB;

/**
 * Apply a verified, provider-neutral refund webhook event to the local
 * refund domain (Step 9.4).
 *
 * Responsibilities (mirrors ReconcilePaymentWebhook, deliberately kept as
 * a separate action):
 *
 *   1. Locate the refund by (provider, provider_refund_id) — the same
 *      indexed pair the schema was designed for.
 *   2. NEVER create refunds. Unknown provider refund IDs are acknowledged
 *      silently (the verified webhook still returns 200) so existence is
 *      never leaked to webhook callers.
 *   3. Lock the refund AND its parent payment before reconciling so
 *      duplicate/concurrent/out-of-order deliveries serialize.
 *   4. Apply strict, documented status transition rules (see isNoOp).
 *   5. Reconcile the parent payment ONLY after a successful refund
 *      transition, using the existing refund-balance helpers — the payment
 *      becomes partially_refunded / refunded based on the accumulated
 *      successful refund total. Failed/cancelled refunds never change the
 *      payment, and duplicate successes never double-count.
 *
 * No external provider calls happen here; the whole reconciliation is a
 * short, local database transaction.
 */
class ReconcileRefundWebhook
{
    /**
     * Reconcile a verified webhook result against the local refund domain.
     */
    public function reconcile(PaymentProviderWebhookResult $webhook): RefundWebhookReconciliationResult
    {
        // Refund webhooks carry a provider refund id; payment webhooks do
        // not, so this action no-ops safely for payment events. Non-productive
        // events (unverifiable or no recognizable status) are ignored.
        if (! $webhook->valid || $webhook->providerRefundId === null) {
            return new RefundWebhookReconciliationResult(found: false, transitioned: false);
        }

        return DB::transaction(function () use ($webhook): RefundWebhookReconciliationResult {
            /** @var Refund|null $refund */
            $refund = Refund::query()
                ->where('provider', $webhook->provider)
                ->where('provider_refund_id', $webhook->providerRefundId)
                ->lockForUpdate()
                ->first();

            if ($refund === null) {
                return new RefundWebhookReconciliationResult(found: false, transitioned: false);
            }

            // Lock the parent payment so refund/payment state can never
            // diverge under concurrent webhook deliveries.
            /** @var Payment|null $payment */
            $payment = Payment::query()->lockForUpdate()->find($refund->payment_id);

            if ($payment === null) {
                return new RefundWebhookReconciliationResult(found: false, transitioned: false);
            }

            $incoming = RefundStatus::tryFrom((string) $webhook->status);
            $current = $refund->status;

            if ($incoming === null || $this->isNoOp($current, $incoming)) {
                return new RefundWebhookReconciliationResult(
                    found: true,
                    transitioned: false,
                    previousStatus: $current->value,
                    currentStatus: $current->value,
                );
            }

            $previous = $current->value;

            $this->applyTransition($refund, $incoming, $webhook);

            $this->reconcilePayment($refund, $payment, $incoming);

            return new RefundWebhookReconciliationResult(
                found: true,
                transitioned: true,
                previousStatus: $previous,
                currentStatus: $refund->refresh()->status->value,
            );
        });
    }

    /**
     * Whether an incoming event must not change the refund.
     *
     * Rules (documented, provider-authoritative):
     *
     *   succeeded -> any event        : no-op. A confirmed success is
     *                                   terminal and never downgraded,
     *                                   even by a stale failed event.
     *   cancelled -> any event        : no-op. Cancelled refunds are
     *                                   abandoned, never resurrected.
     *   failed -> non-succeeded event : no-op. A later provider success
     *                                   confirmation may still correct a
     *                                   failed refund (async/recovery
     *                                   flows), but another failure is
     *                                   redundant.
     */
    private function isNoOp(RefundStatus $current, RefundStatus $incoming): bool
    {
        if ($current === RefundStatus::Succeeded) {
            return true;
        }

        if ($current === RefundStatus::Cancelled) {
            return true;
        }

        if ($current === RefundStatus::Failed) {
            return $incoming !== RefundStatus::Succeeded;
        }

        // pending/processing: an identical event is already represented by
        // the current state (idempotent duplicate delivery).
        return $current === $incoming;
    }

    /**
     * Apply the refund status transition.
     */
    private function applyTransition(
        Refund $refund,
        RefundStatus $incoming,
        PaymentProviderWebhookResult $webhook,
    ): void {
        $metadata = $this->mergeMetadata($refund->response_metadata ?? [], $webhook->metadata);

        switch ($incoming) {
            case RefundStatus::Processing:
                // A provider-confirmed "still processing" event materializes
                // the in-flight state for a refund that was only pending.
                $refund->status = RefundStatus::Processing;
                $refund->provider_refund_id ??= $webhook->providerRefundId;
                break;

            case RefundStatus::Succeeded:
                $refund->status = RefundStatus::Succeeded;
                $refund->provider_refund_id ??= $webhook->providerRefundId;
                $refund->failure_code = null;
                $refund->failure_message = null;
                $refund->response_metadata = $metadata;
                $refund->completed_at = now();
                break;

            case RefundStatus::Failed:
                $refund->status = RefundStatus::Failed;
                $refund->provider_refund_id ??= $webhook->providerRefundId;
                $refund->failure_code ??= 'provider_webhook';
                $refund->failure_message ??= 'Refund failed per provider webhook.';
                $refund->response_metadata = $metadata;
                $refund->completed_at = now();
                break;

            case RefundStatus::Cancelled:
                $refund->status = RefundStatus::Cancelled;
                $refund->completed_at = now();
                break;
        }

        $refund->save();
    }

    /**
     * Reconcile the parent payment after a refund transition.
     *
     * Only a successful refund changes the payment, and only via the
     * accumulated successful-refund total (existing domain helpers):
     *
     *   total < payment amount  -> partially_refunded
     *   total >= payment amount -> refunded
     *
     * Failed/cancelled refunds release their reservation automatically
     * through the refund-balance logic and never mark the payment failed.
     * An already-refunded payment is never downgraded. Duplicate succeeded
     * events never reach this method (isNoOp), so amounts are never
     * double-counted.
     */
    private function reconcilePayment(
        Refund $refund,
        Payment $payment,
        RefundStatus $refundStatus,
    ): void {
        if ($refundStatus !== RefundStatus::Succeeded) {
            return;
        }

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

    /**
     * Merge safe webhook metadata into existing refund response metadata,
     * preferring existing keys so unrelated metadata is never overwritten.
     *
     * @param  array<string, mixed>  $existing
     * @param  array<string, mixed>  $incoming
     * @return array<string, mixed>
     */
    private function mergeMetadata(array $existing, array $incoming): array
    {
        return array_merge($incoming, $existing);
    }
}
