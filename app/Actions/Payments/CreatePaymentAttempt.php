<?php

namespace App\Actions\Payments;

use App\Contracts\Payments\PaymentProvider;
use App\Enums\PaymentAttemptStatus;
use App\Enums\PaymentProviderName;
use App\Exceptions\PaymentProviderException;
use App\Models\Payment;
use App\Models\PaymentAttempt;
use App\Services\Payments\PaymentProviderManager;

/**
 * Create a PaymentAttempt record for a payment.
 *
 * Records the intent to process a payment through a provider — it does
 * NOT call the provider's charge() method (that is the next step), and
 * it does NOT change the Payment status.
 *
 * Tenant safety: merchant_id is copied from the payment itself and is
 * NOT fillable, so arbitrary input can never set it. amount/currency are
 * snapshotted from the payment.
 */
class CreatePaymentAttempt
{
    public function __construct(private readonly PaymentProviderManager $manager) {}

    public function create(Payment $payment, PaymentProviderName|string $provider): PaymentAttempt
    {
        $name = $provider instanceof PaymentProviderName
            ? $provider->value
            : PaymentProviderName::normalize($provider);

        // Reject unknown providers before touching the database.
        if (! PaymentProviderName::isValid($name)) {
            throw PaymentProviderException::unknownProvider($name);
        }

        $providerInstance = $this->manager->resolve($name);

        // Only providers capable of charging may receive an attempt.
        if (! $providerInstance->supports(PaymentProvider::OPERATION_CHARGE)) {
            throw PaymentProviderException::unsupportedOperation($name, PaymentProvider::OPERATION_CHARGE);
        }

        $attempt = $payment->attempts()->make([
            'provider' => $name,
            'status' => PaymentAttemptStatus::Pending,
            'amount' => $payment->amount,
            'currency' => $payment->currency,
        ]);

        // merchant_id is deliberately not fillable; it is assigned
        // directly from the payment so it can never diverge from
        // payment.merchant_id via external input.
        $attempt->merchant_id = $payment->merchant_id;

        $attempt->save();

        return $attempt;
    }
}
