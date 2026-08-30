<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\Payments\PreparePaymentAttempt;
use App\Actions\Payments\ProcessPaymentAttempt;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\CreatePaymentAttemptRequest;
use App\Http\Resources\Api\V1\PaymentProcessingResource;
use App\Services\ApiKeys\ApiRequestContext;
use Illuminate\Http\JsonResponse;

class PaymentProcessingController extends Controller
{
    /**
     * Create a new payment attempt for the referenced payment and process
     * it through the selected (or default) provider.
     *
     * The merchant always comes from ApiRequestContext and the payment is
     * looked up within that merchant only — cross-merchant and unknown
     * references both return a generic 404. Each /process request creates
     * a NEW attempt; a single attempt can never be processed twice.
     * 200 is returned for both outcomes (succeeded and failed) — a
     * failed payment is a successfully handled processing request.
     */
    public function process(
        CreatePaymentAttemptRequest $request,
        PreparePaymentAttempt $prepare,
        ProcessPaymentAttempt $process,
        ApiRequestContext $context,
        string $reference,
    ): JsonResponse {
        $merchant = $context->merchant();

        if ($merchant === null) {
            return response()->json(['message' => 'Invalid API key.'], 401);
        }

        $payment = $merchant->payments()
            ->where('reference', $reference)
            ->first();

        if ($payment === null) {
            return response()->json(['message' => 'Not found.'], 404);
        }

        $attempt = $process->process(
            $prepare->prepare($payment, $request->validated('provider')),
        );

        return response()->json([
            'data' => new PaymentProcessingResource($attempt),
        ]);
    }
}
