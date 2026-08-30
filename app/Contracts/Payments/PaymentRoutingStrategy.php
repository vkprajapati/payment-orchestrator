<?php

declare(strict_types=1);

namespace App\Contracts\Payments;

use App\Data\Payments\PaymentRoutingPlan;
use App\Models\Payment;

/**
 * Strategy for ordering providers when processing a payment.
 *
 * The strategy receives a payment (and therefore its merchant) and returns
 * an ordered list of provider names to attempt, in priority order. The
 * router failovers to the next provider only when a previous attempt
 * fails.
 *
 * Version 1 always returns a single-provider plan (mock) — the contract
 * exists so merchant-specific routing rules can drop in later without
 * touching callers.
 */
interface PaymentRoutingStrategy
{
    /**
     * Resolve the ordered provider plan for the given payment.
     */
    public function resolveProviders(Payment $payment): PaymentRoutingPlan;
}
