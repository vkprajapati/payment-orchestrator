<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Actions\Payments\ProcessPaymentWithFailover;
use App\Exceptions\PaymentNotProcessableException;
use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\PaymentProcessingResource;
use App\Services\ApiKeys\ApiRequestContext;
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
    ): JsonResponse {
        $merchant = $context->merchant();

        if ($merchant === null) {
            return response()->json(['message' => 'Invalid API key.'], 401);
        }

        $payment = $merchant->payments()
            ->where('reference', $reference)
            ->lockForUpdate()
            ->first();

        if ($payment === null) {
            return response()->json(['message' => 'Not found.'], 404);
        }

        try {
            $attempts = $process->process($payment);
        } catch (PaymentNotProcessableException $exception) {
            // A terminal/second processed payment cannot be routed again.
            // Controlled 409 — no internal details, leaks, or stack traces.
            return response()->json([
                'message' => $exception->getMessage(),
                'status' => $payment->refresh()->status->value,
            ], 409);
        }

        // The resource wraps a PaymentAttempt (which has a ->payment
        // relation); pass the last executed attempt so the resource can
        // serialize both the payment and the attempt that settled it.
        $lastAttempt = $attempts[count($attempts) - 1];

        return response()->json(
            ['data' => new PaymentProcessingResource($lastAttempt)],
            200,
        );
    }
}
