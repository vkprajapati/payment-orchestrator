<?php

namespace App\Actions\Audit;

use App\Data\Audit\AuditHealthResult;
use App\Exceptions\InvalidAuditRetentionException;
use App\Models\AuditEvent;
use App\Services\Audit\AuditRetention;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;

/**
 * Operational health check for the audit subsystem.
 *
 * Answers the operator questions "is retention working?" and "is the
 * audit pipeline alive?" using bounded aggregate queries only — the
 * audit table is never loaded row-by-row (one COUNT and one MAX).
 *
 * Checks:
 *   1. retention configuration valid and its window evaluable
 *   2. audit table queryable (stale count + newest event timestamp)
 *   3. no events older than the strict retention cutoff (i.e. pruning
 *      is keeping up) — same cutoff semantics as PruneAuditEvents via
 *      the shared AuditRetention service
 *
 * Failures are fail-safe: an invalid configuration or a database error
 * never throws to the caller — they are reported as unhealthy with a
 * coarse reason marker ('retention_config_invalid',
 * 'database_unavailable'). Internal exception details, merchant
 * identifiers, and audit contents are never included.
 *
 * Health reads never create audit events — no AuditLogger usage.
 */
final class GetAuditHealth
{
    /**
     * Run one health check.
     */
    public function execute(): AuditHealthResult
    {
        $checkedAt = CarbonImmutable::instance(now());

        $retentionConfigValid = true;
        $retentionDays = null;
        $staleCount = null;
        $newestEventAt = null;
        $reason = null;

        try {
            $retentionDays = AuditRetention::days();
            $cutoff = AuditRetention::cutoff($retentionDays);
        } catch (InvalidAuditRetentionException) {
            $retentionConfigValid = false;
            $reason = 'retention_config_invalid';
        }

        if ($retentionConfigValid) {
            try {
                // Strict cutoff: performed_at < cutoff — exactly the rows
                // PruneAuditEvents would delete. Aggregates only.
                $staleCount = (int) AuditEvent::query()
                    ->where('performed_at', '<', $cutoff)
                    ->count();

                // max() returns a raw scalar (or null) — no model hydration.
                $maxPerformedAt = AuditEvent::query()->max('performed_at');
                $newestEventAt = $maxPerformedAt !== null
                    ? CarbonImmutable::parse($maxPerformedAt)
                    : null;
            } catch (QueryException) {
                // Fail safely: the database problem itself is never exposed.
                $staleCount = null;
                $newestEventAt = null;
                $reason = 'database_unavailable';
            }
        }

        if ($reason === null && $staleCount > 0) {
            $reason = 'stale_events_present';
        }

        return new AuditHealthResult(
            healthy: $reason === null,
            retentionConfigValid: $retentionConfigValid,
            retentionDays: $retentionDays,
            staleCount: $staleCount,
            newestEventAt: $newestEventAt,
            checkedAt: $checkedAt,
            reason: $reason,
        );
    }
}
