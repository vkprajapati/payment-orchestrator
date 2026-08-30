<?php

namespace App\Services\ApiKeys;

use App\Models\ApiKey;
use App\Models\Merchant;

/**
 * Holds the API key and merchant resolved from the current request's
 * Bearer token.
 *
 * This is intentionally NOT registered as a plain singleton: a singleton
 * would leak one request's authenticated merchant into the next request
 * under long-running workers (Octane, queue workers). It is bound with
 * Application::scoped() — under PHP-FPM each request gets a fresh container
 * anyway, and wherever Laravel flushes scoped bindings between requests/jobs
 * (Octane calls forgetScopedInstances() after each request, the queue worker
 * after each job) the context is cleared automatically. Eloquent caches the
 * merchant relation on first access, so merchant() never re-queries.
 */
class ApiRequestContext
{
    protected ?ApiKey $apiKey = null;

    /**
     * Attach the authenticated API key to the current request context.
     */
    public function set(ApiKey $apiKey): void
    {
        $this->apiKey = $apiKey;
    }

    /**
     * The authenticated API key, or null when unauthenticated.
     */
    public function apiKey(): ?ApiKey
    {
        return $this->apiKey;
    }

    /**
     * The merchant that owns the authenticated API key, or null.
     *
     * The merchant always comes from the API key's merchant_id — never from
     * headers, query parameters, or the request body.
     */
    public function merchant(): ?Merchant
    {
        return $this->apiKey?->merchant;
    }

    /**
     * Whether a valid API key has been authenticated for this request.
     */
    public function has(): bool
    {
        return $this->apiKey !== null;
    }

    /**
     * Discard the current context.
     */
    public function clear(): void
    {
        $this->apiKey = null;
    }
}
