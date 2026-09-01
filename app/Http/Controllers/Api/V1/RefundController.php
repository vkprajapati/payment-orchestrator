<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\Api\HandleIdempotentRequest;
use App\Actions\Payments\CreateRefund;
use App\Actions\Payments\ProcessRefund;
use App\Enums\AuditEventName;
use App\Enums\AuditOutcome;
use App\Exceptions\PaymentProviderException;
use App\Exceptions\RefundNotProcessableException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\CreateRefundRequest;
use App\Http\Requests\Api\V1\ListRefundsRequest;
use App\Http\Resources\Api\V1\RefundResource;
use App\Models\Merchant;
use App\Services\ApiKeys\ApiRequestContext;
use App\Services\Audit\AuditLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
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
        HandleIdempotentRequest $idempotency,
        AuditLogger $audit,
    ): JsonResponse {
        $merchant = $context->merchant();

        if ($merchant === null) {
            return response()->json(['message' => 'Unauthenticated.'], 401);
        }

        // Idempotency-Key aware: an identical retry replays the stored
        // response instead of creating/executing a second refund; a key
        // reuse for a different logical request is rejected with a
        // controlled 409. The reservation commits before the provider is
        // contacted, so no idempotency lock is ever held during provider
        // HTTP calls. Audit logging happens inside the closure, so a replay
        // never duplicates the refund.created event.
        return $idempotency->wrap(
            $merchant,
            $request,
            $request->validated(),
            fn (): JsonResponse => $this->performStore($reference, $request, $createRefund, $processRefund, $merchant, $audit),
        )->response;
    }

    /**
     * The existing refund creation/execution flow, unchanged.
     */
    private function performStore(
        string $reference,
        CreateRefundRequest $request,
        CreateRefund $createRefund,
        ProcessRefund $processRefund,
        Merchant $merchant,
        AuditLogger $audit,
    ): JsonResponse {
        $payment = $merchant->payments()
            ->where('reference', $reference)
            ->first();

        if ($payment === null) {
            // Unknown/cross-merchant reference: no audit record (see
            // performProcess for the rationale — existence must not leak and
            // probe noise must not pollute the authenticated merchant's trail).
            return response()->json(['message' => 'Not found.'], 404);
        }

        // Concurrency-safe creation: holds a lock on the payment row while
        // validating and calculating the remaining refundable balance, so
        // concurrent refund requests can never over-refund.
        try {
            $refund = $createRefund->createSafely($payment, $request->refundData());
        } catch (InvalidArgumentException $exception) {
            // Controlled domain/validation failure (e.g. over-refund). Recorded
            // as a failure outcome; the refund was never created.
            $audit->log(
                $merchant,
                AuditEventName::RefundCreated,
                $request->method(),
                $request->path(),
                outcome: AuditOutcome::Failure,
                responseStatus: 422,
                paymentReference: $payment->reference,
                metadata: [
                    'amount' => $request->validated('amount'),
                    'currency' => $request->validated('currency') ?? $payment->currency,
                ],
            );

            return response()->json(['message' => $exception->getMessage()], 422);
        }

        try {
            $refund = $processRefund->process($refund);
        } catch (RefundNotProcessableException $exception) {
            // The refund stays pending — no provider was executed.
            $audit->log(
                $merchant,
                AuditEventName::RefundCreated,
                $request->method(),
                $request->path(),
                outcome: AuditOutcome::Failure,
                responseStatus: 409,
                paymentReference: $payment->reference,
                refundReference: $refund->reference,
            );

            return response()->json([
                'message' => $exception->getMessage(),
                'data' => new RefundResource($refund->refresh()),
            ], 409);
        } catch (PaymentProviderException $exception) {
            // The refund stays pending — execution never started.
            $audit->log(
                $merchant,
                AuditEventName::RefundCreated,
                $request->method(),
                $request->path(),
                outcome: AuditOutcome::Failure,
                responseStatus: 422,
                paymentReference: $payment->reference,
                refundReference: $refund->reference,
            );

            return response()->json([
                'message' => $exception->getMessage(),
                'data' => new RefundResource($refund->refresh()),
            ], 422);
        }

        $audit->log(
            $merchant,
            AuditEventName::RefundCreated,
            $request->method(),
            $request->path(),
            outcome: AuditOutcome::Success,
            responseStatus: 201,
            paymentReference: $payment->reference,
            refundReference: $refund->reference,
            metadata: [
                'amount' => $refund->amount,
                'currency' => $refund->currency,
            ],
        );

        return response()->json(
            ['data' => new RefundResource($refund)],
            201,
        );
    }

    /**
     * List refunds belonging to a payment of the authenticated merchant.
     *
     * Isolation happens at the database query level: the lookup starts from
     * the merchant relation, then resolves the payment, then the refunds —
     * so no other merchant's rows can ever match. Pagination defaults to 20
     * per page. Results are ordered newest-first with a deterministic
     * id DESC secondary sort to keep ordering stable when created_at
     * timestamps are identical.
     *
     * Unknown and cross-merchant payments are indistinguishable (both 404).
     */
    public function index(
        string $reference,
        ListRefundsRequest $request,
        ApiRequestContext $context,
    ): AnonymousResourceCollection {
        $merchant = $context->merchant();

        if ($merchant === null) {
            abort(401, 'Invalid API key.');
        }

        $payment = $merchant->payments()
            ->where('reference', $reference)
            ->first();

        if ($payment === null) {
            abort(response()->json(['message' => 'Not found.'], 404));
        }

        $query = $payment->refunds()
            ->orderByDesc('created_at')
            ->orderByDesc('id');

        if ($request->statusFilter() !== null) {
            $query->where('status', $request->statusFilter());
        }

        if ($request->providerFilter() !== null) {
            $query->where('provider', $request->providerFilter());
        }

        return RefundResource::collection($query->paginate($request->perPage()));
    }

    /**
     * Retrieve a single refund belonging to a payment of the authenticated
     * merchant.
     *
     * Security: the payment is resolved within the merchant's scope, then
     * the refund is resolved within that payment's scope. An unknown payment
     * reference, a cross-merchant payment, an unknown refund reference, or a
     * refund belonging to another payment are all indistinguishable (404) —
     * resource existence is never revealed across tenants.
     */
    public function show(
        string $reference,
        string $refundReference,
        ApiRequestContext $context,
    ): RefundResource {
        $merchant = $context->merchant();

        if ($merchant === null) {
            abort(401, 'Invalid API key.');
        }

        $payment = $merchant->payments()
            ->where('reference', $reference)
            ->first();

        if ($payment === null) {
            abort(response()->json(['message' => 'Not found.'], 404));
        }

        $refund = $payment->refunds()
            ->where('reference', $refundReference)
            ->first();

        if ($refund === null) {
            abort(response()->json(['message' => 'Not found.'], 404));
        }

        return new RefundResource($refund);
    }
}
