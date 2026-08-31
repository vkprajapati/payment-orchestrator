<?php

namespace App\Contracts\Payments;

use App\Data\Payments\PaymentProviderResult;
use App\Models\Payment;

/**
 * Contract for a payment provider integration (Stripe, P24, Razorpay,
 * PayU, Mock, ...).
 *
 * Implementations are provider-NEUTRAL consumers of the internal Payment
 * domain: they never decide which provider to use, never update the
 * Payment model, and never touch the database. Provider selection and
 * failover belong to the (future) router layer.
 *
 * Interface segregation: webhook handling lives in the separate
 * PaymentWebhookProvider contract, so providers that cannot receive
 * webhooks are not forced to implement it.
 */
interface PaymentProvider
{
    /**
     * Operation identifier for the charge() method, usable with supports().
     */
    public const OPERATION_CHARGE = 'charge';

    /**
     * Operation identifier for refund execution, usable with supports().
     *
     * Capability checks must distinguish charge and refund: a
     * charge-capable provider is NOT automatically refund-capable, so
     * refund routing must verify supports(OPERATION_REFUND) explicitly.
     * The refund execution itself lives in the segregated RefundProvider
     * contract (same pattern as PaymentWebhookProvider).
     */
    public const OPERATION_REFUND = 'refund';

    /**
     * The stable internal provider identifier (e.g. 'stripe', 'p24').
     * Not a display name — see PaymentProviderName.
     */
    public function name(): string;

    /**
     * Charge the given payment through this provider.
     *
     * Implementations must NOT persist anything and must NOT mutate the
     * Payment model; the caller owns all state transitions.
     *
     * @param  array<string, mixed>  $data  provider-neutral, optional extra data
     */
    public function charge(Payment $payment, array $data = []): PaymentProviderResult;

    /**
     * Whether this provider supports the given operation (see the
     * OPERATION_* constants). Allows capabilities to grow without
     * breaking existing implementations.
     */
    public function supports(string $operation): bool;
}
