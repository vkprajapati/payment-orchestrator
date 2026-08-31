<?php

namespace App\Actions\Payments;

use App\Data\Payments\PaymentProviderWebhookResult;
use App\Data\Payments\WebhookReconciliationResult;
use App\Enums\PaymentAttemptStatus;
use App\Enums\PaymentStatus;
use App\Models\Payment;
use App\Models\PaymentAttempt;
use Illuminate\Support\Facades\DB;

/**
 * Apply a verified, provider-neutral webhook event to the local payment
 * domain.
 *
 * Responsibilities (Step 8.4):
 *
 *   1. Locate the attempt by (provider, provider_payment_id) — the same
 *      indexed pair the schema was designed for.
 *   2. NEVER create payments or attempts. Unknown provider IDs are
 *      acknowledged silently (the verified webhook still returns 200) so
 *      existence is never leaked to webhook callers.
 *   3. Lock the attempt and its parent payment before reconciling so
 *      duplicate/concurrent deliveries serialize.
 *   4. Apply strict, documented status transition rules.
 *   5. Reconcile the parent payment only after an attempt changes, in a
 *      way that never breaks the failover architecture.
 *
 * No external provider calls happen here; the whole reconciliation is a
 * short, local database transaction.
 */
class ReconcilePaymentWebhook
{
    /**
     * Reconcile a verified webhook result against the local domain.
     */
    public function reconcile(PaymentProviderWebhookResult $webhook): WebhookReconciliationResult
    {
        // Unverified or non-productive events (no provider payment id /
        // no recognizable status) are ignored without touching state.
        if (! $webhook->valid || $webhook->providerPaymentId === null) {
            return new WebhookReconciliationResult(found: false, transitioned: false);
        }

        return DB::transaction(function () use ($webhook): WebhookReconciliationResult {
            /** @var PaymentAttempt|null $attempt */
            $attempt = PaymentAttempt::query()
                ->where('provider', $webhook->provider)
                ->where('provider_payment_id', $webhook->providerPaymentId)
                ->lockForUpdate()
                ->first();

            if ($attempt === null) {
                return new WebhookReconciliationResult(found: false, transitioned: false);
            }

            // Lock the parent payment so the attempt/payment pair can never
            // diverge under concurrent webhook deliveries.
            /** @var Payment|null $payment */
            $payment = Payment::query()->lockForUpdate()->find($attempt->payment_id);

            if ($payment === null) {
                return new WebhookReconciliationResult(found: false, transitioned: false);
            }

            $incoming = PaymentAttemptStatus::tryFrom((string) $webhook->status);
            $current = $attempt->status;

            if ($incoming === null || $this->isNoOp($current, $incoming)) {
                return new WebhookReconciliationResult(
                    found: true,
                    transitioned: false,
                    previousStatus: $current->value,
                    currentStatus: $current->value,
                );
            }

            $previous = $current->value;

            $this->applyTransition($attempt, $incoming, $webhook);

            $this->reconcilePayment($attempt, $payment, $incoming);

            return new WebhookReconciliationResult(
                found: true,
                transitioned: true,
                previousStatus: $previous,
                currentStatus: $attempt->refresh()->status->value,
            );
        });
    }

    /**
     * Whether an incoming event must not change the attempt.
     *
     * Rules (documented, provider-authoritative):
     *
     *   succeeded -> any event        : no-op. A confirmed success is
     *                                   terminal and never downgraded,
     *                                   even by a stale failed event.
     *   cancelled -> any event        : no-op. Cancelled attempts are
     *                                   abandoned, never resurrected.
     *   failed -> non-succeeded event : no-op. A later provider success
     *                                   confirmation may still correct a
     *                                   failed attempt (async/recovery
     *                                   flows), but another failure is
     *                                   redundant.
     */
    private function isNoOp(PaymentAttemptStatus $current, PaymentAttemptStatus $incoming): bool
    {
        if ($current === PaymentAttemptStatus::Succeeded) {
            return true;
        }

        if ($current === PaymentAttemptStatus::Cancelled) {
            return true;
        }

        if ($current === PaymentAttemptStatus::Failed) {
            return $incoming !== PaymentAttemptStatus::Succeeded;
        }

        // pending/processing: an identical or downgrading pending event is
        // already represented by the current state.
        return $current === $incoming;
    }

/**
     * Apply the attempt status transition.
     */
    private function applyTransition(
        PaymentAttempt $attempt,
        PaymentAttemptStatus $incoming,
        PaymentProviderWebhookResult $webhook,
    ): void {
        $metadata = $this->mergeMetadata($attempt->response_metadata ?? [], $webhook->metadata);

        switch ($incoming) {
            case PaymentAttemptStatus::Processing:
                // A provider-confirmed "still processing" event materializes
                // the start time state for an attempt that was only pending.
                $attempt->status = PaymentAttemptStatus::Processing;
                $attempt->started_at ??= now();
                break;

            case PaymentAttemptStatus::Succeeded:
                $attempt->status = PaymentAttemptStatus::Succeeded;
                $attempt->provider_payment_id ??= $webhook->providerPaymentId;
                $attempt->failure_code = null;
                $attempt->failure_message = null;
                $attempt->response_metadata = $metadata;
                $attempt->completed_at = now();
                break;

            case PaymentAttemptStatus::Failed:
                $attempt->status = PaymentAttemptStatus::Failed;
                $attempt->provider_payment_id ??= $webhook->providerPaymentId;
                $attempt->failure_code ??= 'provider_webhook';
                $attempt->failure_message ??= 'Payment failed per provider webhook.';
                $attempt->response_metadata = $metadata;
                $attempt->completed_at = now();
                break;
        }

        $attempt->save();
    }

    /**
     * Reconcile the parent payment after an attempt transition.
     *
     * Never breaks failover:
     *
     *   attempt succeeded           -> payment succeeded (unless already
     *                                   in a terminal state, e.g. refunded).
     *   attempt failed, no sibling  -> payment failed ONLY when no other
     *     attempt pending/processing   attempt can still succeed. A single
     *                                   failed provider must not sink a
     *                                   payment another provider could still
     *                                   complete.
     *   pending/processing events   -> payment status untouched.
     */
    private function reconcilePayment(
        PaymentAttempt $attempt,
        Payment $payment,
        PaymentAttemptStatus $attemptStatus,
    ): void {
        if ($payment->isTerminal()) {
            return;
        }

        if ($attemptStatus === PaymentAttemptStatus::Succeeded) {
            if (! $payment->isSucceeded()) {
                $payment->status = PaymentStatus::Succeeded;
                $payment->save();
            }

            return;
        }

        if ($attemptStatus === PaymentAttemptStatus::Failed) {
            $hasPendingOrProcessing = $payment->attempts()
                ->whereIn('status', [
                    PaymentAttemptStatus::Pending->value,
                    PaymentAttemptStatus::Processing->value,
                ])
                ->exists();

            if (! $hasPendingOrProcessing && ! $payment->isSucceeded()) {
                $payment->status = PaymentStatus::Failed;
                $payment->save();
            }
        }
    }

    /**
     * Merge safe webhook metadata into existing attempt response metadata,
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