<?php

namespace Database\Factories;

use App\Enums\PaymentAttemptStatus;
use App\Models\Payment;
use App\Models\PaymentAttempt;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<PaymentAttempt>
 */
class PaymentAttemptFactory extends Factory
{
    protected $model = PaymentAttempt::class;

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
            'payment_id' => PaymentFactory::new(),
            'merchant_id' => MerchantFactory::new(),
            'provider' => 'mock',
            'provider_payment_id' => 'mock_'.Str::random(24),
            'status' => PaymentAttemptStatus::Pending,
            'amount' => fake()->numberBetween(100, 50000),
            'currency' => fake()->randomElement(['USD', 'EUR', 'GBP', 'PLN']),
            'failure_code' => null,
            'failure_message' => null,
            'request_metadata' => null,
            'response_metadata' => null,
            'started_at' => null,
            'completed_at' => null,
        ];
    }

    /**
     * Attach the attempt to a payment and snapshot its amount, currency,
     * and merchant — mirroring what the CreatePaymentAttempt action does.
     */
    public function forPayment(Payment $payment): static
    {
        return $this->state(fn () => [
            'payment_id' => $payment->id,
            'merchant_id' => $payment->merchant_id,
            'amount' => $payment->amount,
            'currency' => $payment->currency,
        ]);
    }

    /**
     * An attempt that completed successfully at the provider.
     */
    public function succeeded(): static
    {
        return $this->state(fn () => [
            'status' => PaymentAttemptStatus::Succeeded,
            'completed_at' => now(),
        ]);
    }

    /**
     * An attempt that failed at the provider.
     */
    public function failed(string $code = 'card_declined', string $message = 'The card was declined.'): static
    {
        return $this->state(fn () => [
            'status' => PaymentAttemptStatus::Failed,
            'failure_code' => $code,
            'failure_message' => $message,
            'completed_at' => now(),
        ]);
    }
}
