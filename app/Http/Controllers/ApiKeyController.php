<?php

namespace App\Http\Controllers;

use App\Actions\ApiKeys\CreateApiKey;
use App\Http\Requests\CreateApiKeyRequest;
use App\Models\ApiKey;
use App\Models\Merchant;
use App\Services\Merchants\CurrentMerchant;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class ApiKeyController extends Controller
{
    /**
     * Session key holding the raw API key for its one-time display.
     *
     * The raw key exists only in the session between the store redirect and
     * the dedicated display page; it is never persisted or logged.
     */
    public const CREATED_KEY_SESSION = 'api_key_created';

    public function __construct(
        protected CurrentMerchant $currentMerchant,
        protected CreateApiKey $createApiKey,
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
     * Create a new API key and redirect to its one-time display page.
     */
    public function store(CreateApiKeyRequest $request): RedirectResponse
    {
        $merchant = $this->requireCurrentMerchant();

        $created = $this->createApiKey->create($merchant, $request->validated('name'));

        $request->session()->flash(self::CREATED_KEY_SESSION, [
            'id' => $created->apiKey->id,
            'raw' => $created->rawKey,
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

        session()->forget(self::CREATED_KEY_SESSION);

        return view('settings.api-keys.created', [
            'apiKey' => $apiKey,
            'rawKey' => (string) $pending['raw'],
        ]);
    }

    /**
     * Revoke the given API key.
     *
     * Revocation never deletes the record. The key must belong to the
     * current merchant; any other key resolves to a 404 so cross-merchant
     * manipulation is not possible.
     */
    public function destroy(Request $request, ApiKey $apiKey): RedirectResponse
    {
        $merchant = $this->requireCurrentMerchant();

        abort_if($apiKey->merchant_id !== $merchant->id, 404, 'API key not found.');

        $this->authorize('delete', $apiKey);

        if (! $apiKey->isRevoked()) {
            $apiKey->forceFill(['revoked_at' => now()])->save();
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
