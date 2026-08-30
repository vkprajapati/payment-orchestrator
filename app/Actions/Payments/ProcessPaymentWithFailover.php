<?php

declare(strict_types=1);

namespace App\Actions\Payments;

use App\Contracts\Payments\PaymentRoutingStrategy;
use App\Enums\PaymentAttemptStatus;
use App\Enums\PaymentStatus;
use App\Exceptions\AttemptNotProcessableException;
use App\Exceptions\PaymentNotProcessableException;
use App\Models\Payment;
use App\Models\PaymentAttempt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Orchestrates routing + attempt creation + failover for a payment.
 *
 * For Version 1 the routing plan contains a single provider (mock), so the
 * "failover" path is structurally present but unexercised. The loop is
 * written so that additional providers flow through automatically once
 * they become eligible — no rewrite required.
 */
class ProcessPaymentWithFailover
{
    public function __construct(
        private readonly PaymentRoutingStrategy $routing,
        private readonly CreatePaymentAttempt $createAttempt,
        private readonly ProcessPaymentAttempt $processAttempt,
    ) {}

    /**
     * Route and process the given payment.
     *
     * The payment must be in a processable state (pending/processing). A
     * terminal payment (succeeded/failed/cancelled) raises a
     * PaymentNotProcessableException so the controller can return a
     * controlled response.
     *
     * Flow:
     *   1. Reject terminal payments.
     *   2. Resolve the ordered provider plan.
     *   3. Transition payment to processing (row-locked).
     *   4. Iterate: create attempt → execute → stop at first success.
     *   5. Finalize the payment status from the outcomes.
     *
     * @return non-empty-list<PaymentAttempt> executed attempts (success stops early)
     *
     * @throws PaymentNotProcessableException when the payment cannot be processed
     */
    public function process(Payment $payment): array
    {
        $this->guardProcessable($payment);

        $plan = $this->routing->resolveProviders($payment);

        $this->transitionToProcessing($payment);

        $results = $this->executeAttempts($payment, $plan);

        $this->finalizePayment($payment, $results);

        return $results;
    }

    /**
     * Prevent re-processing a payment that has already been settled.
     */
    private function guardProcessable(Payment $payment): void
    {
        $payment->refresh();

        if ($payment->isTerminal()) {
            throw PaymentNotProcessableException::terminalState($payment->reference, $payment->status);
        }
    }

    /**
     * Move the payment to processing under row lock.
     */
    private function transitionToProcessing(Payment $payment): void
    {
        DB::transaction(function () use ($payment): void {
            $locked = Payment::query()->lockForUpdate()->findOrFail($payment->id);

            if (! in_array($locked->status, [PaymentStatus::Pending, PaymentStatus::Processing], true)) {
                throw PaymentNotProcessableException::forPayment($locked->reference, $locked->status);
            }

            $locked->status = PaymentStatus::Processing;
            $locked->save();
        });
    }

    /**
     * Iterate the routing plan: create + execute one attempt per provider,
     * stopping at the first success.
     *
     * @return non-empty-list<PaymentAttempt>
     */
    private function executeAttempts(Payment $payment, $plan): array
    {
        $results = [];

        foreach ($plan->providers() as $name) {
            $attempt = $this->createAttempt->create($payment, $name);
            $attempt = $this->executeAttempt($payment, $attempt, $name);
            $results[] = $attempt;

            // Stop at the first successful attempt; record the failure otherwise.
            if ($attempt->status === PaymentAttemptStatus::Succeeded) {
                break;
            }
        }

        return $results;
    }

    /**
     * Execute one attempt via the existing processor.
     *
     * Provider exceptions are logged and converted into a failed attempt;
     * attempt-level state exceptions (AttemptNotProcessableException) are
     * allowed to bubble — they indicate a logic error, not a provider
     * failure.
     */
    private function executeAttempt(Payment $payment, PaymentAttempt $attempt, string $name): PaymentAttempt
    {
        try {
            return $this->processAttempt->process($attempt);
        } catch (AttemptNotProcessableException $e) {
            throw $e;
        } catch (\Throwable $e) {
            Log::warning('Payment provider failed', [
                'payment' => $payment->reference,
                'provider' => $name,
                'class' => get_class($e),
                'message' => $e->getMessage(),
            ]);

            $attempt->markFailed(
                null,
                'provider_exception',
                'The payment provider returned an error.',
                [],
            );

            $attempt->refresh();

            return $attempt;
        }
    }

    /**
     * Synchronize the parent payment's status from the final attempt.
     *
     * Success anywhere ⇒ succeeded. All failures ⇒ failed.
     */
    private function finalizePayment(Payment $payment, array $attempts): void
    {
        $succeeded = collect($attempts)->contains(
            fn (PaymentAttempt $a): bool => $a->status === PaymentAttemptStatus::Succeeded,
        );

        $status = $succeeded ? PaymentStatus::Succeeded : PaymentStatus::Failed;

        if ($payment->status !== $status) {
            $payment->status = $status;
            $payment->save();
        }
    }
}
