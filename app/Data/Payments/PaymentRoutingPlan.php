<?php

declare(strict_types=1);

namespace App\Data\Payments;

/**
 * Immutable, ordered plan of providers to attempt for a single payment.
 *
 * Each entry pairs a provider name with its 0-based priority (the
 * iteration order of the list). The list is bounded by its contents:
 * failover stops when the list is exhausted.
 */
final readonly class PaymentRoutingPlan
{
    /**
     * @param  list<string>  $providers  ordered provider names (priority 0 = first)
     */
    public function __construct(
        public array $providers = [],
    ) {}

    /**
     * The provider names in priority order.
     *
     * @return list<string>
     */
    public function providers(): array
    {
        return $this->providers;
    }
}
