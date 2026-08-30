<?php

namespace App\Actions\Payments;

use App\Contracts\Payments\PaymentProviderResolver;
use App\Models\Payment;
use App\Models\PaymentAttempt;

/**
 * Coordinate provider selection and attempt creation for a payment.
 *
 * Flow: Payment → PaymentProviderResolver (chooses provider)
 *           → CreatePaymentAttempt (records the attempt).
 *
 * This does NOT process money and does NOT mutate the Payment — provider
 * charge() execution belongs to the next step.
 */
class PreparePaymentAttempt
{
    public function __construct(
        private readonly PaymentProviderResolver $resolver,
        private readonly CreatePaymentAttempt $createAttempt,
    ) {}

    /**
     * Prepare a processing attempt for the payment through the resolved
     * provider.
     *
     * @param  string|null  $requestedProvider  explicit provider name, or
     *                                          null to use the resolver's default strategy
     */
    public function prepare(Payment $payment, ?string $requestedProvider = null): PaymentAttempt
    {
        $provider = $this->resolver->resolve($payment, $requestedProvider);

        return $this->createAttempt->create($payment, $provider->name());
    }
}
