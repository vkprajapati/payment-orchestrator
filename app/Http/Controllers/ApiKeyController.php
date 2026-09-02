<?php

namespace App\Http\Controllers;

use App\Actions\ApiKeys\CreateApiKey;
use App\Actions\ApiKeys\RotateApiKey;
use App\Actions\ApiKeys\UpdateApiKeyScopes;
use App\Enums\AuditEventName;
use App\Enums\AuditOutcome;
use App\Http\Requests\Api\V1\UpdateApiKeyScopesRequest;
use App\Http\Requests\CreateApiKeyRequest;
use App\Models\ApiKey;
use App\Models\Merchant;
use App\Services\Audit\AuditLogger;
use App\Services\Merchants\CurrentMerchant;
use Carbon\Carbon;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class ApiKeyController extends Controller
{
    /**
     * Session key holding the raw API key for its one-time display.
     *
     * The raw key exists only in the session between the store/rotate
     * redirect and the dedicated display page; it is never persisted or
     * logged.
     */
    public const CREATED_KEY_SESSION = 'api_key_created';

    public function __construct(
        protected CurrentMerchant $currentMerchant,
        protected CreateApiKey $createApiKey,
        protected AuditLogger $auditLogger,
        protected RotateApiKey $rotateApiKey,
        protected UpdateApiKeyScopes $updateApiKeyScopes,
    ) {}

    /**
     * List the current merchant's API keys, newest first.
     */
    public function index(): View
    {
        $merchant = $this->requireCurrentMerchant();

        $this->authorize('viewAny', [ApiKey::class, $merchant]);

        $apiKeys = $merchant->apiKeys()
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->get();

        return view('settings.api-keys.index', compact('merchant', 'apiKeys'));
    }

    /**
     * Show a single API key's safe metadata, scopes, and lifecycle actions.
     *
     * The key must belong to the current merchant; any other key resolves
     * to the same generic 404 as an unknown key.
     */
    public function show(ApiKey $apiKey): View
    {
        $merchant = $this->requireCurrentMerchant();

        abort_if($apiKey->merchant_id !== $merchant->id, 404, 'API key not found.');

        $this->authorize('viewAny', [ApiKey::class, $merchant]);

        return view('settings.api-keys.show', ['apiKey' => $apiKey]);
    }

    /**
     * Create a new API key and redirect to its one-time display page.
     *
     * The web creation flow is audited exactly like the API flow
     * (api_key.created) — plaintext keys and hashes are never logged.
     */
    public function store(CreateApiKeyRequest $request): RedirectResponse
    {
        $merchant = $this->requireCurrentMerchant();

        $expiresAt = $request->expiresAt() !== null
            ? Carbon::parse($request->expiresAt())->setTime(23, 59, 59)
            : null;

        $created = $this->createApiKey->create(
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
            'settings/api-keys',
            outcome: AuditOutcome::Success,
            responseStatus: 200,
        );

        $request->session()->flash(self::CREATED_KEY_SESSION, [
            'id' => $created->apiKey->id,
            'raw' => $created->rawKey,
            'context' => 'created',
        ]);

        return redirect()->route('settings.api-keys.created', $created->apiKey);
    }

    /**
     * Show the raw API key once, then discard it from the session.
     *
     * The raw key is read from the session (never the database) and the
     * session entry is forgotten immediately, so refreshing the page
     * redirects back to the listing without exposing the key again.
     */
    public function created(ApiKey $apiKey): View|RedirectResponse
    {
        $merchant = $this->requireCurrentMerchant();

        $this->authorize('viewAny', [ApiKey::class, $merchant]);

        $pending = session(self::CREATED_KEY_SESSION);

        if (! is_array($pending) || ($pending['id'] ?? null) !== $apiKey->id) {
            return redirect()->route('settings.api-keys.index');
        }

        $context = $pending['context'] ?? 'created';

        session()->forget(self::CREATED_KEY_SESSION);

        return view('settings.api-keys.created', [
            'apiKey' => $apiKey,
            'rawKey' => (string) $pending['raw'],
            'context' => is_string($context) ? $context : 'created',
        ]);
    }

    /**
     * Replace an API key's scopes (thin web wrapper around the shared
     * domain action used by the API endpoint).
     *
     * The raw secret and key_hash are untouched; an identical re-
     * application is a no-op that produces no audit event.
     */
    public function updateScopes(UpdateApiKeyScopesRequest $request, ApiKey $apiKey): RedirectResponse
    {
        $merchant = $this->requireCurrentMerchant();

        abort_if($apiKey->merchant_id !== $merchant->id, 404, 'API key not found.');

        $this->authorize('delete', $apiKey);

        $oldScopes = $apiKey->scopes ?? [];
        $newScopes = $request->scopes();

        if ($this->updateApiKeyScopes->update($apiKey, $newScopes)) {
            $this->auditLogger->log(
                $merchant,
                AuditEventName::ApiKeyScopesUpdated,
                'PUT',
                'settings/api-keys/'.$apiKey->reference.'/scopes',
                outcome: AuditOutcome::Success,
                responseStatus: 200,
                metadata: [
                    'old_scopes' => $oldScopes,
                    'scopes' => $newScopes,
                ],
            );
        }

        return redirect()
            ->route('settings.api-keys.show', $apiKey)
            ->with('status', 'API key scopes updated.');
    }

    /**
     * Rotate an API key: create a replacement and revoke the old key,
     * then show the new raw secret exactly once.
     *
     * The rotation state change is delegated to the shared RotateApiKey
     * action (same semantics as the API endpoint: replacement inherits
     * name, label, and the exact scope set; the old key is revoked inside
     * the same transaction).
     */
    public function rotate(Request $request, ApiKey $apiKey): RedirectResponse
    {
        $merchant = $this->requireCurrentMerchant();

        abort_if($apiKey->merchant_id !== $merchant->id, 404, 'API key not found.');

        // Creating the replacement (and implicitly revoking the old key)
        // requires the managing authorization used for key creation.
        $this->authorize('create', [ApiKey::class, $merchant]);

        $created = $this->rotateApiKey->rotate($merchant, $apiKey);

        $this->auditLogger->log(
            $merchant,
            AuditEventName::ApiKeyRotated,
            'POST',
            'settings/api-keys/'.$apiKey->reference.'/rotate',
            outcome: AuditOutcome::Success,
            responseStatus: 200,
        );

        $request->session()->flash(self::CREATED_KEY_SESSION, [
            'id' => $created->apiKey->id,
            'raw' => $created->rawKey,
            'context' => 'rotated',
        ]);

        return redirect()->route('settings.api-keys.created', $created->apiKey);
    }

    /**
     * Revoke the given API key.
     *
     * Revocation never deletes the record. The key must belong to the
     * current merchant; any other key resolves to a 404 so cross-merchant
     * manipulation is not possible. Revocation is idempotent — repeated
     * revocation never changes the revoked_at timestamp — and is audited
     * only when an active key is actually revoked.
     */
    public function destroy(Request $request, ApiKey $apiKey): RedirectResponse
    {
        $merchant = $this->requireCurrentMerchant();

        abort_if($apiKey->merchant_id !== $merchant->id, 404, 'API key not found.');

        $this->authorize('delete', $apiKey);

        if (! $apiKey->isRevoked()) {
            $apiKey->forceFill(['revoked_at' => now()])->save();

            $this->auditLogger->log(
                $merchant,
                AuditEventName::ApiKeyRevoked,
                'DELETE',
                'settings/api-keys/'.$apiKey->reference,
                outcome: AuditOutcome::Success,
                responseStatus: 200,
            );
        }

        return redirect()
            ->route('settings.api-keys.index')
            ->with('status', 'API key revoked successfully.');
    }

    /**
     * Resolve the current merchant or abort when no workspace is available.
     */
    protected function requireCurrentMerchant(): Merchant
    {
        $merchant = $this->currentMerchant->get();

        abort_if($merchant === null, 404, 'No workspace is available.');

        return $merchant;
    }
}
