<?php

namespace App\Data\Audit;

use Carbon\CarbonImmutable;

/**
 * Immutable, aggregate-only audit subsystem health snapshot.
 *
 * Carries operational status for monitoring/alerting — never merchant
 * identifiers, event references, metadata, or internal exception details.
 * Nullable query-derived fields are null when the database could not be
 * queried; that failure is reported through the coarse, safe `reason`
 * marker only.
 *
 * Lifecycle awareness: the health snapshot distinguishes active vs.
 * archived events so operators can tell whether archiving is keeping up
 * with the archive cutoff, separate from permanent-pruning progress.
 */
final readonly class AuditHealthResult
{
    /**
     * @param  bool  $healthy  true when retention config is valid, the
     *                         audit table is queryable, no active events are
     *                         older than the archive cutoff, and no events
     *                         are older than the prune cutoff
     * @param  bool  $retentionConfigValid  whether audit.retention.days is usable
     * @param  int|null  $retentionDays  the validated window in days (null when invalid)
     * @param  int|null  $staleCount  active events strictly older than the
     *                                archive cutoff (should be ~0 after
     *                                archiving runs successfully)
     * @param  int|null  $archivedCount  archived (soft-deleted) events
     * @param  int|null  $pruneEligibleCount  archived events strictly older
     *                                        than the prune cutoff (eligible
     *                                        for permanent deletion)
     * @param  CarbonImmutable|null  $newestEventAt  newest performed_at
     *                                               of active events
     * @param  CarbonImmutable|null  $newestArchivedAt  newest deleted_at
     *                                                  of archived events
     * @param  CarbonImmutable  $checkedAt  when the check ran
     * @param  string|null  $reason  coarse, safe failure marker: one of
     *                               'retention_config_invalid',
     *                               'database_unavailable',
     *                               'stale_events_present' — null when healthy
     */
    public function __construct(
        public bool $healthy,
        public bool $retentionConfigValid,
        public ?int $retentionDays,
        public ?int $staleCount,
        public ?int $archivedCount,
        public ?int $pruneEligibleCount,
        public ?CarbonImmutable $newestEventAt,
        public ?CarbonImmutable $newestArchivedAt,
        public CarbonImmutable $checkedAt,
        public ?string $reason,
    ) {}
}
