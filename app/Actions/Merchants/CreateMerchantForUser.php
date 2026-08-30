<?php

namespace App\Actions\Merchants;

use App\Models\Merchant;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class CreateMerchantForUser
{
    /**
     * The role assigned to the user who owns the created merchant.
     */
    public const OWNER_ROLE = 'owner';

    /**
     * Create a default merchant workspace for the given user and attach them as owner.
     */
    public function create(User $user): Merchant
    {
        return DB::transaction(function () use ($user) {
            $name = $this->merchantNameFor($user);

            $merchant = Merchant::create([
                'name' => $name,
                'slug' => $this->uniqueSlugFor($name),
                'status' => 'active',
            ]);

            $merchant->users()->attach($user, ['role' => self::OWNER_ROLE]);

            return $merchant;
        });
    }

    /**
     * Generate the merchant workspace name from the user's name.
     */
    protected function merchantNameFor(User $user): string
    {
        return "{$user->name}'s Workspace";
    }

    /**
     * Generate a unique, URL-safe slug for the given merchant name.
     */
    protected function uniqueSlugFor(string $name): string
    {
        $base = Str::slug($name) ?: Str::slug('Workspace');
        $slug = $base;
        $suffix = 2;

        while (Merchant::where('slug', $slug)->exists()) {
            $slug = $base.'-'.$suffix;
            $suffix++;
        }

        return $slug;
    }
}
