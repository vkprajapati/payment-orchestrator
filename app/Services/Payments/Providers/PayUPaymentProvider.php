<?php

namespace App\Services\Payments\Providers;

use App\Contracts\Payments\PaymentProvider;
use App\Data\Payments\PaymentProviderResult;
use App\Enums\PaymentProviderName;
use App\Exceptions\PaymentProviderException;
use App\Models\Payment;

/**
 * PayU provider — architecture placeholder.
 *
 * Future integration path (dedicated step):
 *  - Order creation (POST /api/v2_1/orders)
 *  - Payment status notifications (callbacks)
 *  - Signature/hash verification of incoming notifications
 *
 * No SDK, credentials, or HTTP calls exist yet; charging fails in a
 * controlled way.
 */
class PayUPaymentProvider implements PaymentProvider
{
    public function name(): string
    {
        return PaymentProviderName::PayU->value;
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
