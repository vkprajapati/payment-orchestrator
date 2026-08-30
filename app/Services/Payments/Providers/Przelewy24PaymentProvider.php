<?php

namespace App\Services\Payments\Providers;

use App\Contracts\Payments\PaymentProvider;
use App\Data\Payments\PaymentProviderResult;
use App\Enums\PaymentProviderName;
use App\Exceptions\PaymentProviderException;
use App\Models\Payment;

/**
 * Przelewy24 (P24) provider — architecture placeholder.
 *
 * Internal identifier is 'p24' (stable, provider-neutral); a display
 * name can be handled separately in the future.
 *
 * Future integration path (dedicated step):
 *  - Transaction registration (POST /transaction/register)
 *  - Payment verification (PUT /transaction/verify)
 *  - Notification (webhook) verification via signed SHA-384 payloads
 *
 * No SDK, credentials, or HTTP calls exist yet; charging fails in a
 * controlled way.
 */
class Przelewy24PaymentProvider implements PaymentProvider
{
    public function name(): string
    {
        return PaymentProviderName::Przelewy24->value;
    }

    public function charge(Payment $payment, array $data = []): PaymentProviderResult
    {
        throw PaymentProviderException::notConfigured($this->name());
    }

    public function supports(string $operation): bool
    {
        return false;
    }
}
