<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\Payments\CreateRefund;
use App\Actions\Payments\ProcessRefund;
use App\Exceptions\PaymentProviderException;
use App\Exceptions\RefundNotProcessableException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\CreateRefundRequest;
use App\Http\Resources\Api\V1\RefundResource;
use App\Models\Merchant;
use App\Services\ApiKeys\ApiRequestContext;
use Illuminate\Http\JsonResponse;
use InvalidArgumentException;

class RefundController extends Controller
{
    /**
     * Create and execute a refund for a payment owned by the merchant
     * authenticated via the API key.
     *
     * Chosen lifecycle (Option A — synchronous creation + execution,
     * mirroring the existing POST /payments/{reference}/process endpoint):
     * the refund is created under a payment row lock and immediately
     * executed through the provider that processed the original payment.
     * The response always carries the FINAL refund state — 201 with status
     * succeeded on success, 201 with status failed when the provider
     * rejected the refund.
     *
     * Security: the payment is looked up ONLY within the authenticated
     * merchant's payments — an unknown reference and a reference owned by
     * another merchant are indistinguishable (both 404), so payment
     * existence is never leaked. Merchant identity comes exclusively from
     * ApiRequestContext, never from request input, and the provider is
     * derived server-side from the original successful attempt.
     */
    public function store(
        string $reference,
        CreateRefundRequest $request,
        ApiRequestContext $context,
        CreateRefund $createRefund,
        ProcessRefund $processRefund,
    ): JsonResponse {
        $merchant = $context->merchant();

        if ($merchant === null) {
            return response()->json(['message' => 'Unauthenticated.'], 401);
        }

        $payment = $merchant->payments()
            ->where('reference', $reference)
            ->first();

        if ($payment === null) {
            return response()->json(['message' => 'Not found.'], 404);
        }

        // Concurrency-safe creation: holds a lock on the payment row while
        // validating and calculating the remaining refundable balance, so
        // concurrent refund requests can never over-refund.
        try {
            $refund = $createRefund->createSafely($payment, $request->refundData());
        } catch (InvalidArgumentException $exception) {
            return response()->json(['message' => $exception->getMessage()], 422);
        }

        try {
            $refund = $processRefund->process($refund);
        } catch (RefundNotProcessableException $exception) {
            // The refund stays pending — no provider was executed.
            return response()->json([
                'message' => $exception->getMessage(),
                'data' => new RefundResource($refund->refresh()),
            ], 409);
        } catch (PaymentProviderException $exception) {
            // The refund stays pending — execution never started.
            return response()->json([
                'message' => $exception->getMessage(),
                'data' => new RefundResource($refund->refresh()),
            ], 422);
        }

        return response()->json(
            ['data' => new RefundResource($refund)],
            201,
        );
    }
}
