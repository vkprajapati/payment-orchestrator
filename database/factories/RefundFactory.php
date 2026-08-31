<?php

namespace Database\Factories;

use App\Enums\RefundStatus;
use App\Models\Payment;
use App\Models\Refund;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Refund>
 */
class RefundFactory extends Factory
{
    protected $model = Refund::class;

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
            'payment_id' => PaymentFactory::new()->succeeded(),
            'reference' => 'ref_'.Str::ulid(),
            'provider' => null,
            'provider_refund_id' => null,
            'amount' => 1000,
            'currency' => fake()->randomElement(['USD', 'EUR', 'GBP', 'PLN']),
            'status' => RefundStatus::Pending,
            'reason' => fake()->optional()->sentence(),
            'failure_code' => null,
            'failure_message' => null,
            'request_metadata' => null,
            'response_metadata' => null,
            'requested_at' => now(),
            'completed_at' => null,
        ];
    }

    /**
     * Attach the refund to a payment and snapshot its merchant and
     * currency — mirroring what the CreateRefund action does. The amount
     * defaults to a full refund of the payment unless overridden.
     */
    public function forPayment(Payment $payment, ?int $amount = null): static
    {
        return $this->state(fn () => [
            'payment_id' => $payment->id,
            'merchant_id' => $payment->merchant_id,
            'amount' => $amount ?? $payment->amount,
            'currency' => $payment->currency,
        ]);
    }

    /**
     * A refund awaiting execution.
     */
    public function pending(): static
    {
        return $this->state(fn () => ['status' => RefundStatus::Pending]);
    }

    /**
     * A refund currently being executed by a provider.
     */
    public function processing(): static
    {
        return $this->state(fn () => ['status' => RefundStatus::Processing]);
    }

    /**
     * A refund that completed successfully at the provider.
     */
    public function succeeded(): static
    {
        return $this->state(fn () => [
            'status' => RefundStatus::Succeeded,
            'provider' => 'mock',
            'provider_refund_id' => 'mock_refund_'.Str::random(20),
            'completed_at' => now(),
        ]);
    }

    /**
     * A refund that failed at the provider.
     */
    public function failed(string $code = 'provider_rejected', string $message = 'The provider rejected the refund.'): static
    {
        return $this->state(fn () => [
            'status' => RefundStatus::Failed,
            'failure_code' => $code,
            'failure_message' => $message,
            'completed_at' => now(),
        ]);
    }

    /**
     * A refund that was cancelled before execution.
     */
    public function cancelled(): static
    {
        return $this->state(fn () => [
            'status' => RefundStatus::Cancelled,
            'completed_at' => now(),
        ]);
    }
}
