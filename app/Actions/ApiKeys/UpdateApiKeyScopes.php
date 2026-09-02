<?php

namespace App\Actions\ApiKeys;

use App\Enums\ApiKeyScope;
use App\Models\ApiKey;

/**
 * Atomically replace an API key's scope set.
 *
 * Shared by the API lifecycle endpoint and the merchant dashboard UI.
 *
 * - The raw secret is never touched — changing scopes never rotates or
 *   regenerates the key material (key_hash is preserved).
 * - The updated scope JSON is written as a single atomic UPDATE (Eloquent
 *   save on one row), so concurrent requests can never apply a
 *   partially-mixed scope set — the last full set wins.
 * - Returns whether the stored set ACTUALLY changed, so callers can keep
 *   audit events meaningful: an identical re-application is a no-op and
 *   produces no audit event.
 */
final class UpdateApiKeyScopes
{
    /**
     * @param  list<string>  $scopes  the new scope values (already
     *                                validated and normalized by the caller)
     * @return bool true when the scope set changed
     */
    public function update(ApiKey $key, array $scopes): bool
    {
        $current = $key->scopes ?? ApiKeyScope::values();

        if ($current === $scopes) {
            return false;
        }

        $key->forceFill(['scopes' => $scopes])->save();

        return true;
    }
}
