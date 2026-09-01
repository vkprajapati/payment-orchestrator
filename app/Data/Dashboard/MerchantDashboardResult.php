<?php

namespace App\Data\Dashboard;

use App\Enums\AuditOutcome;
use App\Enums\PaymentStatus;
use App\Enums\RefundStatus;
use Illuminate\Support\Collection;

/**
 * Immutable, aggregate-only dashboard snapshot for the current merchant.
 *
 * Every count comes from a bounded database aggregate (GROUP BY over the
 * status/outcome columns — never a table scan into PHP), and the recent
 * activity feed is a bounded, column-limited query.
 *
 * Sections are nullable by design: when one aggregate cannot be retrieved
 * (database hiccup) that section reports "unavailable" in the UI while the
 * rest of the dashboard stays usable — a single failed metric never breaks
 * the whole page.
 */
final readonly class MerchantDashboardResult
{
    /**
     * @param  array<string, int>|null  $paymentCounts  PaymentStatus value => count (null when unavailable)
     * @param  array<string, int>|null  $refundCounts  RefundStatus value => count (null when unavailable)
     * @param  int|null  $auditTotal  active audit events for the merchant (null when unavailable)
     * @param  array<string, int>|null  $auditByOutcome  AuditOutcome value => count (null when unavailable)
     * @param  Collection<int, \App\Models\AuditEvent>|null  $recentActivity  bounded newest-first feed of
     *                                                                        safe columns only (null when unavailable)
     */
    public function __construct(
        public ?array $paymentCounts = null,
        public ?array $refundCounts = null,
        public ?int $auditTotal = null,
        public ?array $auditByOutcome = null,
        public ?Collection $recentActivity = null,
    ) {}

    /**
     * Total payments across every known status.
     */
    public function paymentTotal(): int
    {
        return $this->paymentCounts === null ? 0 : array_sum($this->paymentCounts);
    }

    /**
     * Count of payments in one status (0 when the section is unavailable
     * or the merchant simply has none in that status).
     */
    public function paymentCount(PaymentStatus $status): int
    {
        return $this->paymentCounts[$status->value] ?? 0;
    }

    /**
     * Payments that have not reached a terminal state yet.
     */
    public function inFlightPayments(): int
    {
        return $this->paymentCount(PaymentStatus::Pending)
            + $this->paymentCount(PaymentStatus::Processing);
    }

    public function succeededPayments(): int
    {
        return $this->paymentCount(PaymentStatus::Succeeded);
    }

    public function failedPayments(): int
    {
        return $this->paymentCount(PaymentStatus::Failed);
    }

    /**
     * Whether the payment section could be computed at all.
     */
    public function paymentsAvailable(): bool
    {
        return $this->paymentCounts !== null;
    }

    public function refundTotal(): int
    {
        return $this->refundCounts === null ? 0 : array_sum($this->refundCounts);
    }

    public function refundCount(RefundStatus $status): int
    {
        return $this->refundCounts[$status->value] ?? 0;
    }

    public function successfulRefunds(): int
    {
        return $this->refundCount(RefundStatus::Succeeded);
    }

    public function failedRefunds(): int
    {
        return $this->refundCount(RefundStatus::Failed);
    }

    public function refundsAvailable(): bool
    {
        return $this->refundCounts !== null;
    }

    public function auditOutcomeCount(AuditOutcome $outcome): int
    {
        return $this->auditByOutcome[$outcome->value] ?? 0;
    }

    public function auditAvailable(): bool
    {
        return $this->auditTotal !== null;
    }

    /**
     * Whether every section is available AND the merchant has no activity
     * at all — drives the dashboard empty state.
     */
    public function hasNoActivity(): bool
    {
        return $this->paymentsAvailable()
            && $this->refundsAvailable()
            && $this->auditAvailable()
            && $this->paymentTotal() === 0
            && $this->refundTotal() === 0
            && $this->auditTotal === 0;
    }
}