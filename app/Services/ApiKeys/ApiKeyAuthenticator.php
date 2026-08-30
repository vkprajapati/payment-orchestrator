<?php

namespace App\Services\ApiKeys;

use App\Actions\ApiKeys\CreateApiKey;
use App\Exceptions\InvalidApiKeyException;
use App\Models\ApiKey;
use Illuminate\Support\Facades\Hash;

class ApiKeyAuthenticator
{
    /**
     * Expected shape of a raw API key, mirroring the generation strategy in
     * CreateApiKey: the "sk_test_" prefix followed by exactly 40 characters.
     * Str::random() only produces alphanumeric characters, so the pattern
     * is intentionally strict — malformed tokens are rejected before any
     * database work happens.
     */
    protected const KEY_PATTERN = '/^sk_test_[A-Za-z0-9]{40}$/';

    /**
     * Authenticate a raw bearer token and return its API key.
     *
     * Lookup strategy: extract the cleartext prefix stored at creation time
     * (CreateApiKey::STORED_PREFIX_LENGTH) and query the indexed
     * api_keys.key_prefix column for candidates, then verify the full raw
     * key against each candidate's bcrypt hash. Revoked and expired keys are
     * rejected even when the hash matches, and last_used_at is only touched
     * after every check has passed.
     *
     * Every failure — unknown key, wrong secret, revoked key, expired key,
     * malformed token — throws the same exception so no internal state is
     * ever revealed, and raw keys are never logged.
     *
     * @throws InvalidApiKeyException
     */
    public function authenticate(?string $rawKey): ApiKey
    {
        if ($rawKey === null || preg_match(self::KEY_PATTERN, $rawKey) !== 1) {
            throw new InvalidApiKeyException;
        }

        $apiKey = $this->findMatchingKey($rawKey);

        if (! $apiKey->isActive()) {
            throw new InvalidApiKeyException;
        }

        $apiKey->forceFill(['last_used_at' => now()])->save();

        return $apiKey;
    }

    /**
     * Find the API key matching the given raw key using the indexed prefix.
     *
     * The prefix narrows the query to a small candidate set; the bcrypt hash
     * check then confirms the exact secret. Same-prefix/different-secret
     * attacks are stopped here because Hash::check() fails.
     *
     * @throws InvalidApiKeyException
     */
    protected function findMatchingKey(string $rawKey): ApiKey
    {
        $prefix = substr($rawKey, 0, CreateApiKey::STORED_PREFIX_LENGTH);

        $candidates = ApiKey::query()
            ->where('key_prefix', $prefix)
            ->get();

        foreach ($candidates as $candidate) {
            if (Hash::check($rawKey, $candidate->key_hash)) {
                return $candidate;
            }
        }

        throw new InvalidApiKeyException;
    }
}
