<?php

namespace Database\Factories;

use App\Actions\ApiKeys\CreateApiKey;
use App\Models\ApiKey;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class ApiKeyFactory extends Factory
{
    protected $model = ApiKey::class;

    public function definition(): array
    {
        // The raw key is generated and hashed here but never returned,
        // mirroring production behavior: once created, the raw secret
        // cannot be recovered. Tests that need to authenticate with a
        // known secret should use withRawKey() or the CreateApiKey action.
        $rawKey = CreateApiKey::KEY_PREFIX.Str::random(CreateApiKey::SECRET_LENGTH);
        $reference = CreateApiKey::REFERENCE_PREFIX.(string) Str::ulid();

        return [
            'reference' => $reference,
            'merchant_id' => MerchantFactory::new(),
            'name' => fake()->randomElement(['Production Server', 'Development', 'Mobile Application', 'CI/CD']),
            'label' => fake()->optional()->word(),
            'key_prefix' => substr($rawKey, 0, CreateApiKey::STORED_PREFIX_LENGTH),
            'key_hash' => Hash::make($rawKey),
            'last_used_at' => null,
            'expires_at' => null,
            'revoked_at' => null,
            'metadata' => null,
        ];
    }

    /**
     * Attributes that correctly hash a known raw key, so tests can
     * authenticate with a secret they hold.
     *
     * @return array{key_prefix: string, key_hash: string}
     */
    public static function attributesForRawKey(string $rawKey): array
    {
        return [
            'key_prefix' => substr($rawKey, 0, CreateApiKey::STORED_PREFIX_LENGTH),
            'key_hash' => Hash::make($rawKey),
        ];
    }

    /**
     * Create a key that can be authenticated with the given raw secret.
     */
    public function withRawKey(string $rawKey): static
    {
        return $this->state(fn () => self::attributesForRawKey($rawKey));
    }

    /**
     * A key that has been revoked (remains stored for audit history).
     */
    public function revoked(): static
    {
        return $this->state(fn () => ['revoked_at' => now()]);
    }

    /**
     * A key whose expiration has passed.
     */
    public function expired(): static
    {
        return $this->state(fn () => ['expires_at' => now()->subMinute()]);
    }
}
