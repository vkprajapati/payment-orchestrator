<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\Payments\PreparePaymentAttempt;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\CreatePaymentAttemptRequest;
use App\Http\Resources\Api\V1\PaymentAttemptResource;
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
        $merchant = $context->merchant();

        // Defensive: api.key guarantees a merchant; never fall through.
        if ($merchant === null) {
            return response()->json(['message' => 'Invalid API key.'], 401);
        }

        // Merchant-scoped lookup: an unknown reference and a reference
        // owned by another merchant are indistinguishable. Not using
        // firstOrFail() because its exception message would leak the
        // internal model class in error payloads.
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
}
