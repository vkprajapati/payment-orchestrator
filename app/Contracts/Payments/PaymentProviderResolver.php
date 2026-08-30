<?php

namespace App\Contracts\Payments;

use App\Exceptions\PaymentProviderException;
use App\Models\Payment;

/**
 * Decides WHICH payment provider should process a payment.
 *
 * This is the routing foundation. Responsibility boundaries are strict:
 * the resolver only selects a provider — it never charges, never
 * persists anything, and never mutates the Payment. Provider
 * implementations are resolved through the PaymentProviderManager.
 */
interface PaymentProviderResolver
{
    /**
     * Resolve the provider to use for the given payment.
     *
     * @param  string|null  $requestedProvider  explicit provider name
     *                                          (normalized case-insensitively); when null the
     *                                          resolver's fallback strategy applies
     *
     * @throws PaymentProviderException when an explicitly
     *                                  requested provider is unknown
     */
    public function resolve(Payment $payment, ?string $requestedProvider = null): PaymentProvider;
}
