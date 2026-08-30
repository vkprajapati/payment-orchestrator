<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\Payments\CreateIdempotentPayment;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\CreatePaymentRequest;
use App\Http\Requests\Api\V1\ListPaymentsRequest;
use App\Http\Resources\Api\V1\PaymentResource;
use App\Models\Merchant;
use App\Services\ApiKeys\ApiRequestContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

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

    /**
     * List payments belonging to the authenticated merchant, newest first.
     *
     * Isolation happens at the database query level: the query starts from
     * the merchant relation, so no other merchant's rows can ever match.
     * The secondary id DESC ordering keeps results deterministic when
     * created_at timestamps are identical.
     */
    public function index(ListPaymentsRequest $request, ApiRequestContext $context): AnonymousResourceCollection
    {
        $merchant = $this->authenticatedMerchant($context);

        $payments = $merchant->payments()
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->paginate($request->perPage());

        return PaymentResource::collection($payments);
    }

    /**
     * Retrieve a single payment of the authenticated merchant by its
     * public reference. The query is merchant-scoped, so a payment of
     * another merchant (or an unknown reference) indistinguishably
     * results in 404 — existence is never revealed across merchants.
     */
    public function show(string $reference, ApiRequestContext $context): PaymentResource
    {
        $merchant = $this->authenticatedMerchant($context);

        // Merchant-scoped lookup: an unknown reference and a reference
        // owned by another merchant indistinguishably result in 404 —
        // existence is never revealed across merchants. Not using
        // firstOrFail() because its exception message would leak the
        // internal model class in error payloads.
        $payment = $merchant->payments()
            ->where('reference', $reference)
            ->first();

        if ($payment === null) {
            abort(response()->json(['message' => 'Not found.'], 404));
        }

        return new PaymentResource($payment);
    }

    /**
     * Resolve the merchant authenticated by the API key.
     *
     * Defensive: the api.key middleware guarantees a merchant, but a
     * missing context must never fall through to an accidental data leak.
     */
    private function authenticatedMerchant(ApiRequestContext $context): Merchant
    {
        $merchant = $context->merchant();

        if ($merchant === null) {
            abort(401, 'Invalid API key.');
        }

        return $merchant;
    }
}
