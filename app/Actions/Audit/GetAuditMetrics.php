<?php

namespace App\Actions\Audit;

use App\Data\Audit\AuditMetricsResult;
use App\Http\Requests\Api\V1\ListAuditEventsRequest;
use App\Models\Merchant;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;

/**
 * Merchant-scoped audit metrics.
 *
 * Produces safe aggregate operational insight (totals, per-event and
 * per-outcome grouping, deterministic time range) using ONLY database
 * aggregates — the audit table is never loaded row-by-row, so memory use
 * is bounded regardless of event volume.
 *
 * Every query structurally begins from the authenticated merchant's
 * relation and reuses the shared AuditEvent::filtered() scope, so the
 * filtering semantics are identical to the list and export endpoints and
 * tenant isolation is enforced before any aggregation.
 *
 * Reads never create audit events — no AuditLogger usage anywhere.
 */
final class GetAuditMetrics
{
    /**
     * Compute the metrics for one merchant within the requested filters.
     */
    public function execute(Merchant $merchant, ListAuditEventsRequest $request): AuditMetricsResult
    {
        $total = $this->filteredQuery($merchant, $request)->count();

        // One grouped row per distinct event name — at most the number of
        // AuditEventName cases, never one row per audit event.
        $byEvent = $this->filteredQuery($merchant, $request)
            ->selectRaw('event, count(*) as aggregate')
            ->groupBy('event')
            ->pluck('aggregate', 'event')
            ->map(fn (mixed $count): int => (int) $count)
            ->all();

        // Rows with a NULL outcome belong to no enum category; they count
        // towards total but are never fabricated into a grouping.
        $byOutcome = $this->filteredQuery($merchant, $request)
            ->whereNotNull('outcome')
            ->selectRaw('outcome, count(*) as aggregate')
            ->groupBy('outcome')
            ->pluck('aggregate', 'outcome')
            ->map(fn (mixed $count): int => (int) $count)
            ->all();

        // Deterministic time range: min/max performed_at over the same
        // filtered set (a single aggregate row, not a scan of events).
        $range = $this->filteredQuery($merchant, $request)
            ->selectRaw('min(performed_at) as range_from, max(performed_at) as range_to')
            ->first();

        return new AuditMetricsResult(
            total: $total,
            byEvent: $byEvent,
            byOutcome: $byOutcome,
            from: $range?->range_from !== null
                ? CarbonImmutable::parse($range->range_from)
                : null,
            to: $range?->range_to !== null
                ? CarbonImmutable::parse($range->range_to)
                : null,
        );
    }

    /**
     * Fresh merchant-scoped, filtered query builder for each aggregate.
     * The query always starts from the merchant relation — the tenant
     * constraint exists before any aggregation is applied.
     *
     * getQuery() exposes the base query builder BEFORE the SoftDeletes
     * global scope is applied, so the active-row constraint (deleted_at IS
     * NULL — archived events are excluded from merchant-facing aggregates)
     * is stated explicitly here.
     */
    private function filteredQuery(Merchant $merchant, ListAuditEventsRequest $request): Builder
    {
        return $merchant->auditEvents()
            ->getQuery()
            ->whereNull('audit_events.deleted_at')
            ->filtered(
                $request->eventFilter(),
                $request->outcomeFilter(),
                $request->from(),
                $request->to(),
            );
    }
}
