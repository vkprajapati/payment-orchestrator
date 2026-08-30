<?php

namespace App\Services\Payments\Providers;

use App\Contracts\Payments\PaymentProvider;
use App\Data\Payments\PaymentProviderResult;
use App\Enums\PaymentProviderName;
use App\Exceptions\PaymentProviderException;
use App\Models\Payment;

/**
 * Razorpay provider — architecture placeholder.
 *
 * Future integration path (dedicated step):
 *  - Orders API (order creation before checkout)
 *  - Payments API (capture/fetch)
 *  - Signature verification (X-Razorpay-Signature, HMAC-SHA256)
 *  - Payment events (payment.captured, payment.failed, ...)
 *
 * No SDK, credentials, or HTTP calls exist yet; charging fails in a
 * controlled way.
 */
class RazorpayPaymentProvider implements PaymentProvider
{
    public function name(): string
    {
        return PaymentProviderName::Razorpay->value;
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
