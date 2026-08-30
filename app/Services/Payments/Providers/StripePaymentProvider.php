<?php

namespace App\Services\Payments\Providers;

use App\Contracts\Payments\PaymentProvider;
use App\Data\Payments\PaymentProviderResult;
use App\Enums\PaymentProviderName;
use App\Exceptions\PaymentProviderException;
use App\Models\Payment;

/**
 * Stripe provider — architecture placeholder.
 *
 * Future integration path (dedicated step):
 *  - Payment Intents API via the official stripe/stripe-php SDK
 *  - Webhook signature verification (Stripe-Signature header)
 *  - Payment status events (payment_intent.succeeded, etc.)
 *
 * No SDK, credentials, or HTTP calls exist yet; charging fails in a
 * controlled way so callers can distinguish "not implemented" from a
 * real provider failure.
 */
class StripePaymentProvider implements PaymentProvider
{
    public function name(): string
    {
        return PaymentProviderName::Stripe->value;
    }

    public function charge(Payment $payment, array $data = []): PaymentProviderResult
    {
        throw PaymentProviderException::notConfigured($this->name());
    }

    public function supports(string $operation): bool
    {
        // Charging exists as an interface obligation, but no operation is
        // actually usable until the real integration lands.
        return false;
    }
}
