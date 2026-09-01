<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Actions\Api\HandleIdempotentRequest;
use App\Actions\Payments\ProcessPaymentWithFailover;
use App\Enums\AuditEventName;
use App\Enums\AuditOutcome;
use App\Exceptions\PaymentNotProcessableException;
use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\PaymentProcessingResource;
use App\Models\Merchant;
use App\Services\ApiKeys\ApiRequestContext;
use App\Services\Audit\AuditLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PaymentProcessingController extends Controller
{
    /**
     * Route a pending payment through the provider chain with failover.
     *
     * The merchant always comes from ApiRequestContext (the API key) —
     * never from request input. The payment is looked up ONLY within
     * the authenticated merchant's payments, so cross-merchant and
     * unknown references are indistinguishable (both 404).
     *
     * 200 is returned for both outcomes (succeeded and failed) — a
     * failed payment is a successfully handled processing request.
     */
    public function process(
        string $reference,
        Request $request,
        ProcessPaymentWithFailover $process,
        ApiRequestContext $context,
        HandleIdempotentRequest $idempotency,
        AuditLogger $audit,
    ): JsonResponse {
        $merchant = $context->merchant();

        if ($merchant === null) {
            return response()->json(['message' => 'Invalid API key.'], 401);
        }

        // Idempotency-Key aware: an identical retry replays the stored
        // response instead of routing the payment through the provider
        // chain a second time; a key reuse for a different logical request
        // (path/body) is rejected with a controlled 409. Audit logging
        // happens inside the closure, so a replay never duplicates the
        // processing audit event.
        return $idempotency->wrap(
            $merchant,
            $request,
            $request->json()->all(),
            fn (): JsonResponse => $this->performProcess($reference, $process, $merchant, $request, $audit),
        )->response;
    }

    /**
     * The existing processing flow, unchanged. The idempotency reservation
     * commits before this runs, so no lock is held while providers are
     * contacted; the failover architecture manages its own transactions.
     */
    private function performProcess(
        string $reference,
        ProcessPaymentWithFailover $process,
        Merchant $merchant,
        Request $request,
        AuditLogger $audit,
    ): JsonResponse {
        $payment = $merchant->payments()
            ->where('reference', $reference)
            ->lockForUpdate()
            ->first();

        if ($payment === null) {
            // Unknown/cross-merchant reference: no audit record. Writing a
            // row here would pollute the authenticated merchant's audit
            // trail for every probe, and the reference may belong to another
            // merchant (existence must never be leaked).
            return response()->json(['message' => 'Not found.'], 404);
        }

        try {
            $attempts = $process->process($payment);
        } catch (PaymentNotProcessableException $exception) {
            // A terminal/second processed payment cannot be routed again.
            // Controlled 409 — recorded as a failure outcome.
            $response = response()->json([
                'message' => $exception->getMessage(),
                'status' => $payment->refresh()->status->value,
            ], 409);

            $audit->log(
                $merchant,
                AuditEventName::PaymentProcessingRequested,
                $request->method(),
                $request->path(),
                outcome: AuditOutcome::Failure,
                responseStatus: 409,
                paymentReference: $payment->reference,
                metadata: ['status' => $payment->status->value],
            );

            return $response;
        }

        // The resource wraps a PaymentAttempt (which has a ->payment
        // relation); pass the last executed attempt so the resource can
        // serialize both the payment and the attempt that settled it.
        $lastAttempt = $attempts[count($attempts) - 1];

        $response = response()->json(
            ['data' => new PaymentProcessingResource($lastAttempt)],
            200,
        );

        // Processing can settle as succeeded OR as a handled provider
        // failure (both are a 200 "request processed"). Outcome reflects
        // the actual payment result, keeping the audit trail meaningful.
        $settledPayment = $lastAttempt->payment;

        $audit->log(
            $merchant,
            AuditEventName::PaymentProcessingRequested,
            $request->method(),
            $request->path(),
            outcome: $settledPayment->isSucceeded() ? AuditOutcome::Success : AuditOutcome::Failure,
            responseStatus: 200,
            paymentReference: $settledPayment->reference,
            metadata: [
                'provider' => $lastAttempt->provider,
                'status' => $settledPayment->status->value,
            ],
        );

        return $response;
    }
}
