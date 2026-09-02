<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Actions\Api\HandleIdempotentRequest;
use App\Actions\ApiKeys\CreateApiKey;
use App\Actions\ApiKeys\RotateApiKey;
use App\Actions\ApiKeys\UpdateApiKeyScopes;
use App\Enums\ApiKeyScope;
use App\Enums\AuditEventName;
use App\Enums\AuditOutcome;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\CreateApiKeyRequest;
use App\Http\Requests\Api\V1\RevokeApiKeyRequest;
use App\Http\Requests\Api\V1\RotateApiKeyRequest;
use App\Http\Requests\Api\V1\UpdateApiKeyScopesRequest;
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
        protected UpdateApiKeyScopes $updateApiKeyScopes,
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
                    $request->scopes(),
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

    /**
     * Replace an API key's scopes.
     *
     * Merchant-scoped lookup: unknown and cross-merchant references return
     * identical 404s. The raw secret is never touched — changing scopes
     * never rotates or regenerates the key material.
     *
     * Self-lockout: updating the CURRENTLY-AUTHENTICATED key's scopes is
     * allowed. This request has already passed the route's api_keys:write
     * scope check under the key's previous scope set, so it completes
     * successfully; the new scopes take effect immediately for every
     * SUBSEQUENT request. If the key removes api_keys:write from itself,
     * later privileged requests are rejected with a generic 403.
     *
     * Idempotency: replacing a scope set is naturally idempotent —
     * applying the same set twice yields the same state, no artifact or
     * secret is ever created, and only the first actual change writes an
     * audit event. HandleIdempotentRequest is deliberately NOT used here
     * (it exists for operations producing one-time secrets), so this is a
     * deterministic state overwrite rather than a replay-scoped mutation.
     *
     * Concurrency: the updated scope JSON is written as a single atomic
     * UPDATE (Eloquent save on one row), so concurrent requests can never
     * apply a partially-mixed scope set — the last full set wins.
     */
    public function updateScopes(
        string $reference,
        UpdateApiKeyScopesRequest $request,
        ApiRequestContext $context,
    ): JsonResponse {
        $merchant = $this->authenticatedMerchant($context);

        $key = $merchant->apiKeys()
            ->where('reference', $reference)
            ->first();

        if ($key === null) {
            return response()->json(['message' => 'Not found.'], 404);
        }

        $oldScopes = $key->scopes ?? ApiKeyScope::values();
        $newScopes = $request->scopes();

        if ($this->updateApiKeyScopes->update($key, $newScopes)) {
            $this->auditLogger->log(
                $merchant,
                AuditEventName::ApiKeyScopesUpdated,
                'PUT',
                'api/v1/api-keys/'.$key->reference.'/scopes',
                outcome: AuditOutcome::Success,
                responseStatus: 200,
                metadata: [
                    'old_scopes' => $oldScopes,
                    'scopes' => $newScopes,
                ],
            );
        }

        return (new ApiKeyResource($key->refresh()))->response();
    }

    /**
     * Rotate an API key: create a replacement and revoke the old key.
     *
     * The replacement inherits the old key's name and label; its raw
     * secret is shown exactly once in the rotation response. Unknown and
     * cross-merchant references return identical 404s. Rotating the
     * currently-authenticated key is allowed (the primary rotation use
     * case): this request is already authenticated, and the old key is
     * revoked inside the transaction before the response is returned —
     * the replacement key authenticates from that point on.
     *
     * Atomicity: replacement creation, old-key revocation, and the audit
     * record all happen inside one database transaction. The operation is
     * purely local (no external HTTP), so a failure can never leave a
     * successful response without its replacement key or with the old key
     * still active.
     *
     * Idempotency: wrapped in HandleIdempotentRequest like creation — a
     * retried rotation with the same Idempotency-Key replays the stored
     * response (same replacement reference and raw secret) without
     * creating additional keys or duplicating the audit event.
     */
    public function rotate(
        string $reference,
        RotateApiKeyRequest $request,
        ApiRequestContext $context,
        RotateApiKey $rotateApiKey,
        HandleIdempotentRequest $idempotency,
    ): JsonResponse {
        $merchant = $this->authenticatedMerchant($context);

        $key = $merchant->apiKeys()
            ->where('reference', $reference)
            ->first();

        if ($key === null) {
            return response()->json(['message' => 'Not found.'], 404);
        }

        return $idempotency->wrap(
            $merchant,
            $request,
            $request->validated(),
            function () use ($request, $key, $rotateApiKey, $merchant): JsonResponse {
                // Shared rotation action: replacement creation (inheriting
                // name, label, and the exact scope set) and old-key
                // revocation happen inside one atomic transaction.
                $created = $rotateApiKey->rotate($merchant, $key);

                $this->auditLogger->log(
                    $merchant,
                    AuditEventName::ApiKeyRotated,
                    'POST',
                    'api/v1/api-keys/'.$key->reference.'/rotate',
                    outcome: AuditOutcome::Success,
                    responseStatus: 201,
                );

                // raw_key is shown exactly once, in the rotation response.
                $payload = (new ApiKeyResource($created->apiKey))->resolve($request);
                $payload['raw_key'] = $created->rawKey;

                return response()->json(['data' => $payload], 201);
            },
        )->response;
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
