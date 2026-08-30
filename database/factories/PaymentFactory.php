<?php

namespace Database\Factories;

use App\Enums\PaymentStatus;
use App\Models\Payment;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Payment>
 */
class PaymentFactory extends Factory
{
    protected $model = Payment::class;

    /**
     * Define the model's default state.
     *
     * Amounts are integers in the smallest currency unit (e.g. 1050
     * represents $10.50) — never floats.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'merchant_id' => MerchantFactory::new(),
            'reference' => 'pay_'.Str::ulid(),
            'idempotency_key' => null,
            'amount' => fake()->numberBetween(100, 50000),
            'currency' => fake()->randomElement(['USD', 'EUR', 'GBP', 'PLN']),
            'status' => PaymentStatus::Pending,
            'description' => fake()->optional()->sentence(),
            'metadata' => null,
        ];
    }

    /**
     * A payment that completed successfully.
     */
    public function succeeded(): static
    {
        return $this->state(fn () => ['status' => PaymentStatus::Succeeded]);
    }

    /**
     * A payment that failed.
     */
    public function failed(): static
    {
        return $this->state(fn () => ['status' => PaymentStatus::Failed]);
    }
}
