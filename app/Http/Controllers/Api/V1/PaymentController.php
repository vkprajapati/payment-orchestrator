<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\Payments\CreateIdempotentPayment;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\CreatePaymentRequest;
use App\Http\Resources\Api\V1\PaymentResource;
use App\Services\ApiKeys\ApiRequestContext;
use Illuminate\Http\JsonResponse;

class PaymentController extends Controller
{
    /**
     * Create a payment for the merchant authenticated by the API key.
     *
     * The merchant always comes from ApiRequestContext (resolved from the
     * Bearer key server-side) — merchant identity is never accepted from
     * request input. 201 signals a newly created payment; 200 signals an
     * idempotent replay of an existing one.
     */
    public function store(
        CreatePaymentRequest $request,
        CreateIdempotentPayment $action,
        ApiRequestContext $context,
    ): JsonResponse {
        $merchant = $context->merchant();

        // Defensive: the api.key middleware guarantees a merchant, but a
        // missing context must never fall through to an accidental insert.
        if ($merchant === null) {
            return response()->json(['message' => 'Invalid API key.'], 401);
        }

        $result = $action->create(
            $merchant,
            [
                'amount' => $request->validated('amount'),
                'currency' => $request->validated('currency'),
                'description' => $request->validated('description'),
                'metadata' => $request->validated('metadata'),
            ],
            $request->idempotencyKey(),
        );

        return response()
            ->json(['data' => new PaymentResource($result->payment)], $result->created ? 201 : 200)
            ->header('Idempotent-Replayed', $result->created ? 'false' : 'true');
    }
}
