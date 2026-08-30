<?php

namespace App\Policies;

use App\Models\Merchant;
use App\Models\User;

class MerchantPolicy
{
    /**
     * Determine whether the user can update the given merchant.
     *
     * Membership is checked against the specific merchant through the pivot
     * table, and only the "owner" and "admin" roles are allowed to update
     * workspace settings. The current merchant session is never trusted alone.
     */
    public function update(User $user, Merchant $merchant): bool
    {
        $role = $user->merchants()
            ->where('merchants.id', $merchant->id)
            ->first()
            ?->pivot
            ?->role;

        return in_array($role, ['owner', 'admin'], true);
    }
}
