<?php

namespace App\Actions\ApiKeys;

use App\Models\Merchant;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class CreateApiKey
{
    /**
     * The environment prefix applied to every generated key. Version 1
     * supports test keys only; live keys may be added later by extending
     * this constant set.
     */
    public const KEY_PREFIX = 'sk_test_';

    /**
     * Length of the random secret portion of the key.
     *
     * Str::random() uses random_bytes() (a cryptographically secure random
     * number generator), so the generated secret is safe for secrets.
     */
    public const SECRET_LENGTH = 40;

    /**
     * Number of leading characters of the raw key stored in cleartext as
     * key_prefix. Enough to identify the key in the dashboard without
     * exposing the secret portion.
     */
    public const STORED_PREFIX_LENGTH = 16;

    /**
     * Create an API key for the given merchant.
     *
     * The raw key is generated with a cryptographically secure generator,
     * hashed with Laravel's bcrypt hashing, and never persisted in
     * cleartext. Verification of a presented bearer token later works by
     * looking up candidate keys with the token's prefix
     * (Api::key_prefix is indexed) and calling Hash::check() against each
     * candidate's key_hash — the high-entropy key makes brute force
     * attempts infeasible.
     */
    public function create(Merchant $merchant, string $name): CreatedApiKey
    {
        $rawKey = self::KEY_PREFIX.Str::random(self::SECRET_LENGTH);

        $apiKey = $merchant->apiKeys()->create([
            'name' => $name,
            'key_prefix' => substr($rawKey, 0, self::STORED_PREFIX_LENGTH),
            'key_hash' => Hash::make($rawKey),
        ]);

        return new CreatedApiKey($apiKey, $rawKey);
    }
}
