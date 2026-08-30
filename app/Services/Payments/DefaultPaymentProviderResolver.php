<?php

namespace App\Services\Payments;

use App\Contracts\Payments\PaymentProvider;
use App\Contracts\Payments\PaymentProviderResolver;
use App\Enums\PaymentProviderName;
use App\Models\Payment;

/**
 * Default provider resolver.
 *
 * TEMPORARY, deliberately simple strategy:
 *
 *   1. An explicitly requested provider is used as-is (validated by the
 *      PaymentProviderManager — unknown names throw).
 *   2. Otherwise the mock provider is used.
 *
 * FUTURE (documented, NOT implemented here): merchant provider
 * configuration → routing rules → priority → success rates → failover.
 * Those features will slot in behind this contract without touching
 * callers.
 */
class DefaultPaymentProviderResolver implements PaymentProviderResolver
{
    public function __construct(private readonly PaymentProviderManager $manager) {}

    public function resolve(Payment $payment, ?string $requestedProvider = null): PaymentProvider
    {
        $name = $requestedProvider !== null && $requestedProvider !== ''
            ? $requestedProvider
            : PaymentProviderName::Mock->value;

        // Case-insensitive resolution; throws for unknown providers.
        return $this->manager->resolve($name);
    }
}
