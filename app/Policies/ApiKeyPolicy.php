<?php

namespace App\Policies;

use App\Models\ApiKey;
use App\Models\Merchant;
use App\Models\User;

class ApiKeyPolicy
{
    /**
     * Roles allowed to view, create, and revoke API keys.
     */
    protected const MANAGING_ROLES = ['owner', 'admin', 'developer'];

    /**
     * Determine whether the user can view the API keys of the merchant.
     *
     * Membership is checked against the specific merchant through the pivot
     * table. The current merchant session is never trusted alone.
     */
    public function viewAny(User $user, ?Merchant $merchant = null): bool
    {
        return $merchant !== null
            && in_array($this->membershipRole($user, $merchant->id), self::MANAGING_ROLES, true);
    }

    /**
     * Determine whether the user can create API keys for the merchant.
     */
    public function create(User $user, ?Merchant $merchant = null): bool
    {
        return $this->viewAny($user, $merchant);
    }

    /**
     * Determine whether the user can revoke the given API key.
     *
     * The key's merchant is resolved from the model itself, so a user can
     * never revoke keys belonging to a merchant they do not manage.
     */
    public function delete(User $user, ApiKey $apiKey): bool
    {
        return in_array($this->membershipRole($user, $apiKey->merchant_id), self::MANAGING_ROLES, true);
    }

    /**
     * Get the user's membership role for the given merchant, if any.
     */
    protected function membershipRole(User $user, int $merchantId): ?string
    {
        return $user->merchants()
            ->where('merchants.id', $merchantId)
            ->first()
            ?->pivot
            ?->role;
    }
}
