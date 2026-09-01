<?php

namespace App\Models;

use App\Enums\ApiKeyScope;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['reference', 'label', 'name', 'key_prefix', 'key_hash', 'last_used_at', 'expires_at', 'revoked_at', 'metadata', 'scopes'])]
#[Hidden(['key_hash', 'key_prefix'])]
class ApiKey extends Model
{
    use HasFactory;

    /**
     * Get the merchant that owns the API key.
     */
    public function merchant(): BelongsTo
    {
        return $this->belongsTo(Merchant::class);
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'last_used_at' => 'datetime',
            'expires_at' => 'datetime',
            'revoked_at' => 'datetime',
            'metadata' => 'array',
            'scopes' => 'array',
        ];
    }

    /**
     * Whether the key holds the given scope.
     *
     * A NULL scopes value (rows that predate per-key scopes and were not
     * backfilled) means full access — existing integrations must never
     * silently lose permissions. Backfilled and newly created rows carry
     * explicit scope lists.
     */
    public function hasScope(string $scope): bool
    {
        $scopes = $this->scopes ?? ApiKeyScope::values();

        return in_array($scope, $scopes, true);
    }

    /**
     * Determine whether the key has been revoked.
     *
     * Revoked keys remain in the database for audit history.
     */
    public function isRevoked(): bool
    {
        return $this->revoked_at !== null;
    }

    /**
     * Determine whether the key has passed its expiration date.
     *
     * Expiration is not enforced by middleware yet, but the model already
     * supports it so future authentication can reject expired keys.
     */
    public function isExpired(): bool
    {
        return $this->expires_at !== null && $this->expires_at->isPast();
    }

    /**
     * Determine whether the key is currently usable.
     */
    public function isActive(): bool
    {
        return ! $this->isRevoked() && ! $this->isExpired();
    }

    /**
     * Whether the authenticated merchant can use this key right now.
     *
     * Used by the API lifecycle endpoints to confirm the key is not
     * revoked or expired, without exposing the reason on failure.
     */
    public function isAccessible(): bool
    {
        return $this->revoked_at === null && ($this->expires_at === null || $this->expires_at->isFuture());
    }
}
