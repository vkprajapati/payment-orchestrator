<?php

declare(strict_types=1);

namespace App\Services\Payments;

use App\Contracts\Payments\PaymentProvider;
use App\Contracts\Payments\PaymentRoutingStrategy;
use App\Data\Payments\PaymentRoutingPlan;
use App\Enums\PaymentProviderName;
use App\Models\Payment;

/**
 * Default provider routing strategy.
 *
 * Version 1 returns only the mock provider: it is the only provider whose
 * supports() capability check passes at runtime, so all others are safely
 * excluded. The full provider order is declared for documentation and for
 * future config-driven activation when the real integrations ship.
 */
class DefaultPaymentRoutingStrategy implements PaymentRoutingStrategy
{
    /**
     * Default provider priority order.
     *
     * Only providers that are both registered AND report supports(charge)
     * at runtime are included — so Stripe/P24/Razorpay/PayU stay excluded
     * until their integrations are complete, with no code change here.
     */
    public const DEFAULT_PROVIDER_ORDER = [
        PaymentProviderName::Stripe,
        PaymentProviderName::Przelewy24,
        PaymentProviderName::Razorpay,
        PaymentProviderName::PayU,
        PaymentProviderName::Mock,
    ];

    public function __construct(
        private readonly PaymentProviderManager $manager,
    ) {}

    public function resolveProviders(Payment $payment): PaymentRoutingPlan
    {
        $eligible = [];

        foreach (self::DEFAULT_PROVIDER_ORDER as $provider) {
            $name = $provider->value;

            if (! $this->manager->has($name)) {
                continue;
            }

            $instance = $this->manager->resolve($name);

            if (! $instance->supports(PaymentProvider::OPERATION_CHARGE)) {
                continue;
            }

            $eligible[] = $name;
        }

        return new PaymentRoutingPlan(providers: $eligible);
    }
}
