<?php

namespace Database\Factories;

use App\Enums\IdempotencyStatus;
use App\Models\IdempotencyKey;
use App\Models\Merchant;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<IdempotencyKey>
 */
class IdempotencyKeyFactory extends Factory
{
    protected $model = IdempotencyKey::class;

    /**
     * Define the model's default state: a reserved (processing) request
     * with no stored response yet.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'merchant_id' => Merchant::factory(),
            'key' => 'idem_'.Str::ulid(),
            'request_method' => 'POST',
            'request_path' => '/api/v1/payments',
            'request_hash' => hash('sha256', 'seed-'.Str::ulid()),
            'status' => IdempotencyStatus::Processing,
            'response_status' => null,
            'response_body' => null,
            'locked_at' => now(),
            'completed_at' => null,
        ];
    }

    /**
     * A finished reservation whose exact response can be replayed.
     */
    public function completed(int $responseStatus = 201, string $responseBody = '{"data":{}}'): static
    {
        return $this->state(fn () => [
            'status' => IdempotencyStatus::Completed,
            'response_status' => $responseStatus,
            'response_body' => $responseBody,
            'completed_at' => now(),
        ]);
    }
}
