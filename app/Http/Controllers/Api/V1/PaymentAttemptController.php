<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\Payments\PreparePaymentAttempt;
use App\Actions\Payments\ProcessPaymentAttempt;
use App\Exceptions\AttemptNotProcessableException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\CreatePaymentAttemptRequest;
use App\Http\Resources\Api\V1\PaymentAttemptResource;
use App\Models\Merchant;
use App\Services\ApiKeys\ApiRequestContext;
use Illuminate\Http\JsonResponse;

class PaymentAttemptController extends Controller
{
    /**
     * Prepare a provider attempt for a payment owned by the merchant
     * authenticated via the API key.
     *
     * Security: the payment is looked up ONLY within the authenticated
     * merchant's payments — an unknown reference and a reference owned by
     * another merchant are indistinguishable (both 404), so payment
     * existence is never leaked. No money is processed here; the attempt
     * is created in pending status only.
     */
    public function store(
        string $reference,
        CreatePaymentAttemptRequest $request,
        ApiRequestContext $context,
        PreparePaymentAttempt $action,
    ): JsonResponse {
        $merchant = $this->authenticatedMerchant($context);

        $payment = $merchant->payments()
            ->where('reference', $reference)
            ->first();

        if ($payment === null) {
            return response()->json(['message' => 'Not found.'], 404);
        }

        $attempt = $action->prepare($payment, $request->requestedProvider());

        return response()->json(
            ['data' => new PaymentAttemptResource($attempt)],
            201,
        );
    }

    /**
     * Execute a pending payment attempt through its provider.
     *
     * Security: both the payment AND the attempt are resolved within the
     * authenticated merchant's scope. An unknown reference, a cross-merchant
     * payment, or an attempt belonging to another payment are all
     * indistinguishable (404) — resource existence is never leaked. A
     * terminal attempt (already succeeded/failed/cancelled) cannot be
     * re-executed and returns a controlled 409 response.
     */
    public function execute(
        string $reference,
        int $attempt,
        ApiRequestContext $context,
        ProcessPaymentAttempt $action,
    ): JsonResponse {
        $merchant = $this->authenticatedMerchant($context);

        // Resolve the payment within the merchant's scope.
        $payment = $merchant->payments()
            ->where('reference', $reference)
            ->first();

        if ($payment === null) {
            return response()->json(['message' => 'Not found.'], 404);
        }

        // Resolve the attempt within the payment's scope.
        $attempt = $payment->attempts()
            ->whereKey($attempt)
            ->first();

        if ($attempt === null) {
            return response()->json(['message' => 'Not found.'], 404);
        }

        try {
            $result = $action->process($attempt);
        } catch (AttemptNotProcessableException $exception) {
            // A terminal attempt cannot be re-executed.
            return response()->json([
                'message' => $exception->getMessage(),
                'status' => $attempt->refresh()->status->value,
            ], 409);
        }

        return response()->json(
            ['data' => new PaymentAttemptResource($result)],
            200,
        );
    }

    /**
     * Resolve the authenticated merchant from the API context.
     *
     * Defensive: the api.key middleware guarantees a merchant; never fall
     * through to an accidental operation without one.
     */
    private function authenticatedMerchant(ApiRequestContext $context): ?Merchant
    {
        return $context->merchant();
    }
}
