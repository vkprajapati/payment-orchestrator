<?php

namespace App\Contracts\Payments;

use App\Data\Payments\PaymentProviderResult;
use App\Models\Payment;
use App\Models\PaymentAttempt;
use App\Models\Refund;

/**
 * Contract for providers that can execute refunds.
 *
 * Interface segregation (same pattern as PaymentWebhookProvider): only
 * providers that genuinely implement a refund API implement this contract.
 * Refund routing must additionally verify supports(OPERATION_REFUND) so a
 * charge-capable provider is never accidentally treated as refund-capable.
 *
 * Implementations are side-effect free: no database writes, no model
 * mutation — the caller (ProcessRefund) owns all state transitions.
 *
 * SECURITY: results never contain credentials, client secrets, or raw
 * provider responses — only normalized, safe data.
 */
interface RefundProvider
{
    /**
     * Execute the given refund through this provider.
     *
     * The result reuses the provider-neutral PaymentProviderResult DTO:
     * for refund execution, the providerPaymentId field carries the
     * provider's REFUND identifier, and status is normalized to a
     * RefundStatus value (succeeded/failed).
     */
    public function refund(Payment $payment, PaymentAttempt $attempt, Refund $refund): PaymentProviderResult;
}
