<?php

namespace App\Actions\ApiKeys;

use App\Models\ApiKey;
use App\Models\Merchant;
use Illuminate\Support\Facades\DB;

/**
 * Rotate an API key: create a replacement and revoke the old key.
 *
 * Shared by the API lifecycle endpoints and the merchant dashboard UI so
 * both channels exhibit identical rotation semantics.
 *
 * The replacement inherits the old key's name, label, and EXACT scope set
 * — rotation must never escalate or silently drop permissions. A legacy
 * NULL scopes value keeps its full-access semantics. The replacement has
 * no expiration (the old key's expiration is not carried over), matching
 * the established rotation contract.
 *
 * Atomicity: replacement creation and old-key revocation happen inside
 * one database transaction. The operation is purely local (no external
 * HTTP), so a failure can never leave the old key revoked without its
 * replacement existing. The raw secret of the replacement is available
 * exactly once via the returned CreatedApiKey DTO and is never persisted.
 *
 * Revocation is idempotent — a revoked_at timestamp is never overwritten
 * once set.
 */
final class RotateApiKey
{
    public function __construct(
        protected CreateApiKey $createApiKey,
    ) {}

    public function rotate(Merchant $merchant, ApiKey $key): CreatedApiKey
    {
        return DB::transaction(function () use ($key, $merchant): CreatedApiKey {
            // Replacement first: if anything below fails, the transaction
            // rolls it back together with the revocation.
            $created = $this->createApiKey->create(
                $merchant,
                $key->name,
                $key->label,
                null,
                $key->scopes,
            );

            if (! $key->isRevoked()) {
                $key->forceFill(['revoked_at' => now()])->save();
            }

            return $created;
        });
    }
}
