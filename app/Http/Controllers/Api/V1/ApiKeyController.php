<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Actions\Api\HandleIdempotentRequest;
use App\Actions\ApiKeys\CreateApiKey;
use App\Enums\AuditEventName;
use App\Enums\AuditOutcome;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\CreateApiKeyRequest;
use App\Http\Requests\Api\V1\RevokeApiKeyRequest;
use App\Http\Resources\Api\V1\ApiKeyResource;
use App\Models\Merchant;
use App\Services\ApiKeys\ApiRequestContext;
use App\Services\Audit\AuditLogger;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Symfony\Component\HttpFoundation\Response;

class ApiKeyController extends Controller
{
    public function __construct(
        protected AuditLogger $auditLogger,
    ) {}

    /**
     * List the authenticated merchant's API keys, newest first.
     *
     * Never exposes plaintext, hashes, key_prefix, or merchant_id.
     */
    public function index(ApiRequestContext $context): AnonymousResourceCollection
    {
        return ApiKeyResource::collection(
            $this->authenticatedMerchant($context)->apiKeys()
                ->orderByDesc('created_at')
                ->orderByDesc('id')
                ->get(),
        );
    }

    /**
     * Retrieve a single API key by its public reference.
     *
     * Unknown and cross-merchant references are indistinguishable (404).
     */
    public function show(string $reference, ApiRequestContext $context): ApiKeyResource|JsonResponse
    {
        $key = $this->authenticatedMerchant($context)
            ->apiKeys()
            ->where('reference', $reference)
            ->first();

        if ($key === null) {
            return response()->json(['message' => 'Not found.'], 404);
        }

        return new ApiKeyResource($key);
    }

    /**
     * Create a new API key for the authenticated merchant.
     *
     * The raw key is shown exactly once in the creation response and is
     * never persisted or retrievable again. Creation is audit-logged
     * exactly once; idempotent replays (with an Idempotency-Key header)
     * return the cached creation response, including the raw key, without
     * generating a second key.
     *
     * Idempotency decision: API-key creation is a sensitive mutation that
     * produces a one-time secret. Reusing HandleIdempotentRequest mirrors
     * PaymentController::store: a retried POST with the same Idempotency-Key
     * replays the stored response (with the raw key) exactly once, while a
     * second key is never generated.
     */
    public function store(
        CreateApiKeyRequest $request,
        ApiRequestContext $context,
        CreateApiKey $createApiKey,
        HandleIdempotentRequest $idempotency,
    ): JsonResponse {
        $merchant = $this->authenticatedMerchant($context);

        $response = $idempotency->wrap(
            $merchant,
            $request,
            $request->validated(),
            function () use ($request, $createApiKey, $merchant): JsonResponse {
                $expiresAt = $request->expiresAt() !== null
                    ? Carbon::parse($request->expiresAt())->setTime(23, 59, 59)
                    : null;

                $created = $createApiKey->create(
                    $merchant,
                    $request->name(),
                    $request->label(),
                    $expiresAt,
                );

                $this->auditLogger->log(
                    $merchant,
                    AuditEventName::ApiKeyCreated,
                    'POST',
                    'api/v1/api-keys',
                    outcome: AuditOutcome::Success,
                    responseStatus: 201,
                );

                // raw_key is shown exactly once, in the creation response.
                // It is never returned from list/show/revoke.
                $payload = (new ApiKeyResource($created->apiKey))->resolve($request);
                $payload['raw_key'] = $created->rawKey;

                return response()->json(['data' => $payload], 201);
            },
        )->response;

        // Replay uses 200 (already created), first execution uses 201.
        return $response;
    }

    /**
     * Revoke an API key.
     *
     * Revocation sets revoked_at (never deletes the record). Unknown and
     * cross-merchant references return identical 404s. Repeated revocation
     * is idempotent — the revoked_at timestamp never changes. The calling
     * key cannot be revoked before the response completes.
     */
    public function revoke(
        string $reference,
        RevokeApiKeyRequest $request,
        ApiRequestContext $context,
    ): JsonResponse {
        $merchant = $this->authenticatedMerchant($context);

        $key = $merchant->apiKeys()
            ->where('reference', $reference)
            ->first();

        if ($key === null) {
            return response()->json(['message' => 'Not found.'], 404);
        }

        // Prevent revoking the currently-authenticated key (would cause
        // a mid-request auth failure). Returns a safe 403.
        if ($context->apiKey()?->reference === $key->reference) {
            return response()->json(['message' => 'Cannot revoke the active key.'], 403);
        }

        $wasRevoked = $key->isRevoked();

        if (! $wasRevoked) {
            $key->forceFill(['revoked_at' => now()])->save();

            $this->auditLogger->log(
                $merchant,
                AuditEventName::ApiKeyRevoked,
                'POST',
                'api/v1/api-keys/'.$key->reference.'/revoke',
                outcome: AuditOutcome::Success,
                responseStatus: 200,
                metadata: $request->validated('reason') !== null
                    ? ['reason' => $request->validated('reason')]
                    : [],
            );
        }

        return (new ApiKeyResource($key))->response();
    }

    private function authenticatedMerchant(ApiRequestContext $context): Merchant
    {
        $merchant = $context->merchant();

        if ($merchant === null) {
            abort(401, 'Invalid API key.');
        }

        return $merchant;
    }
}
