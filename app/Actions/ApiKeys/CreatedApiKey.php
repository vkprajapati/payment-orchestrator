<?php

namespace App\Actions\ApiKeys;

use App\Models\ApiKey;

/**
 * Result of creating an API key.
 *
 * The raw key is only available at creation time and is never persisted.
 */
final readonly class CreatedApiKey
{
    public function __construct(
        public ApiKey $apiKey,
        public string $rawKey,
    ) {}
}
