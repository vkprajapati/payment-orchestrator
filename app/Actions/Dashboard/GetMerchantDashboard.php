<?php

namespace App\Actions\Dashboard;

use App\Data\Dashboard\MerchantDashboardResult;
use App\Models\Merchant;
use Illuminate\Database\QueryException;
use Illuminate\Support\Collection;

/**
 * Build the merchant dashboard snapshot.
 *
 * A read-model for the V1 dashboard: every section is a bounded database
 * aggregate (GROUP BY over status/outcome columns — at most one row per
 * enum case, never one row per payment/refund/audit event), plus one
 * column-limited recent-activity feed. The full payment/refund/audit
 * tables are never hydrated.
 *
 * Every query structurally begins from the authenticated merchant's
 * relation, so tenant isolation is enforced before any aggregation — the
 * dashboard can never mix in another merchant's numbers.
 *
 * Sections fail independently: a QueryException in one aggregate nulls
 * that section only (rendered as "unavailable") and never takes down the
 * whole dashboard. No exception details ever reach the view.
 *
 * Reads never create audit events — no AuditLogger usage anywhere.
 */
final class GetMerchantDashboard
{
    /**
     * How many recent audit activity items the dashboard shows.
     */
    public const RECENT_ACTIVITY_LIMIT = 8;

    /**
     * Columns safe to expose in the recent-activity feed. Mirrors the
     * audit resource whitelist — no metadata, no internal ids, no
     * merchant identifiers.
     *
     * @var list<string>
     */
    private const RECENT_ACTIVITY_COLUMNS = [
        'reference',
        'event',
        'outcome',
        'payment_reference',
        'refund_reference',
        'performed_at',
    ];

    public function execute(Merchant $merchant): MerchantDashboardResult
    {
        return new MerchantDashboardResult(
            paymentCounts: $this->paymentCounts($merchant),
            refundCounts: $this->refundCounts($merchant),
            auditTotal: $this->auditTotal($merchant),
            auditByOutcome: $this->auditByOutcome($merchant),
            recentActivity: $this->recentActivity($merchant),
        );
    }

    /**
     * Payment counts grouped by status — at most one row per status.
     *
     * @return array<string, int>|null
     */
    private function paymentCounts(Merchant $merchant): ?array
    {
        try {
            return $this->countsByColumn($merchant->payments(), 'status');
        } catch (QueryException) {
            return null;
        }
    }

    /**
     * Refund counts grouped by status — at most one row per status.
     *
     * @return array<string, int>|null
     */
    private function refundCounts(Merchant $merchant): ?array
    {
        try {
            return $this->countsByColumn($merchant->refunds(), 'status');
        } catch (QueryException) {
            return null;
        }
    }

    /**
     * Total active audit events for the merchant.
     */
    private function auditTotal(Merchant $merchant): ?int
    {
        try {
            return (int) $merchant->auditEvents()->count();
        } catch (QueryException) {
            return null;
        }
    }

    /**
     * Audit counts grouped by outcome — at most one row per enum case.
     *
     * @return array<string, int>|null
     */
    private function auditByOutcome(Merchant $merchant): ?array
    {
        try {
            return $this->countsByColumn(
                $merchant->auditEvents()->whereNotNull('outcome'),
                'outcome',
            );
        } catch (QueryException) {
            return null;
        }
    }

    /**
     * The bounded recent-activity feed: newest first, limited, and
     * restricted to explicitly safe columns (no metadata, no internal
     * identifiers). The secondary id DESC ordering keeps results
     * deterministic when performed_at values tie.
     *
     * @return Collection<int, \App\Models\AuditEvent>|null
     */
    private function recentActivity(Merchant $merchant): ?Collection
    {
        try {
            return $merchant->auditEvents()
                ->select(self::RECENT_ACTIVITY_COLUMNS)
                ->orderByDesc('performed_at')
                ->orderByDesc('id')
                ->limit(self::RECENT_ACTIVITY_LIMIT)
                ->get();
        } catch (QueryException) {
            return null;
        }
    }

    /**
     * One grouped COUNT query over the given relation's column.
     *
     * The column is aliased (status as status_value) because the model
     * attributes are cast to enums — plucking the cast attribute would
     * produce enum keys. The alias is a raw string straight from the
     * database, matching the array<string, int> shape of the result.
     *
     * @param  \Illuminate\Database\Eloquent\Relations\HasMany<\Illuminate\Database\Eloquent\Model>  $relation
     * @return array<string, int>
     */
    private function countsByColumn($relation, string $column): array
    {
        return $relation
            ->selectRaw($column.' as '.$column.'_value, count(*) as aggregate')
            ->groupBy($column)
            ->get()
            ->pluck('aggregate', $column.'_value')
            ->map(static fn (mixed $count): int => (int) $count)
            ->all();
    }
}