<?php

namespace App\Actions\Payments;

use App\Data\Payments\PaymentProviderResult;
use App\Enums\PaymentStatus;
use App\Exceptions\AttemptNotProcessableException;
use App\Models\PaymentAttempt;
use App\Services\Payments\PaymentProviderManager;
use Illuminate\Support\Facades\DB;
use Throwable;

class ProcessPaymentAttempt
{
    /**
     * Generic message persisted/returned when a provider throws. The
     * underlying exception message may contain credentials or internal
     * details and is never exposed — it is only reported to the log.
     */
    private const PROVIDER_FAILURE_MESSAGE = 'Provider processing failed.';

    public function __construct(private readonly PaymentProviderManager $manager) {}

    /**
     * Process a payment attempt through its provider.
     *
     * Transaction design (Step 7.3):
     *
     *   Transaction 1 — lock the attempt row, re-verify it is still
     *   pending (guards duplicate processing), mark it processing, move
     *   the parent payment to processing, then COMMIT before contacting
     *   the provider. No long-lived locks are held during future slow
     *   external HTTP calls.
     *
     *   Provider call — runs OUTSIDE any transaction.
     *
     *   Transaction 2 — lock the attempt again and atomically persist
     *   the provider result to both the attempt and its parent payment,
     *   so the pair can never diverge (e.g. attempt=succeeded while
     *   payment=pending).
     *
     * @throws AttemptNotProcessableException when the attempt is not pending
     */
    public function process(PaymentAttempt $attempt): PaymentAttempt
    {
        [$attemptId, $provider] = DB::transaction(function () use ($attempt) {
            $attempt = PaymentAttempt::query()
                ->lockForUpdate()
                ->findOrFail($attempt->id);

            if (! $attempt->canProcess()) {
                throw AttemptNotProcessableException::forStatus($attempt->status);
            }

            $provider = $this->manager->resolve($attempt->getRawOriginal('provider'));

            $attempt->markProcessing();
            $this->updatePaymentStatus($attempt, PaymentStatus::Processing);

            return [$attempt->id, $provider];
        });

        $payment = PaymentAttempt::findOrFail($attemptId)->payment;

        try {
            // Request metadata takes precedence over payment metadata so a
            // single payment can be driven differently per attempt.
            $data = array_merge($payment->metadata ?? [], $payment->toArray()['metadata'] ?? []);

            $result = $provider->charge($payment, $data);
        } catch (Throwable $exception) {
            report($exception);

            $result = new PaymentProviderResult(
                success: false,
                provider: $provider->name(),
                providerPaymentId: null,
                status: 'failed',
                message: self::PROVIDER_FAILURE_MESSAGE,
                failureCode: 'provider_exception',
                metadata: [],
            );
        }

        return $this->persistResult($attemptId, $result);
    }

    /**
     * Atomically persist the provider outcome to the attempt and its
     * parent payment.
     */
    private function persistResult(int $attemptId, PaymentProviderResult $result): PaymentAttempt
    {
        return DB::transaction(function () use ($attemptId, $result) {
            $attempt = PaymentAttempt::query()
                ->lockForUpdate()
                ->findOrFail($attemptId);

            if ($result->success) {
                $attempt->markSucceeded($result->providerPaymentId, $result->metadata);
                $this->updatePaymentStatus($attempt, PaymentStatus::Succeeded);
            } else {
                $attempt->markFailed(
                    $result->providerPaymentId,
                    $result->failureCode,
                    $result->message,
                    $result->metadata,
                );
                $this->updatePaymentStatus($attempt, PaymentStatus::Failed);
            }

            return $attempt->refresh();
        });
    }

    /**
     * Move the parent payment to the given status (no-op when unchanged).
     */
    private function updatePaymentStatus(PaymentAttempt $attempt, PaymentStatus $status): void
    {
        $payment = $attempt->payment;

        if ($payment->status !== $status) {
            $payment->status = $status;
            $payment->save();
        }
    }
}
