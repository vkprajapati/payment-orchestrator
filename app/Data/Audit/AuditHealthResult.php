<?php

namespace App\Data\Audit;

use Carbon\CarbonImmutable;

/**
 * Immutable, aggregate-only audit subsystem health snapshot.
 *
 * Carries operational status for monitoring/alerting — never merchant
 * identifiers, event references, metadata, or internal exception details.
 * Nullable query-derived fields (stale_events, newest_event_at) are null
 * when the database could not be queried; that failure is reported
 * through the coarse, safe `reason` marker only.
 */
final readonly class AuditHealthResult
{
    /**
     * @param  bool  $healthy  true when retention config is valid, the
     *                         audit table is queryable, and no events are
     *                         older than the retention cutoff
     * @param  bool  $retentionConfigValid  whether audit.retention.days is usable
     * @param  int|null  $retentionDays  the validated window in days (null when invalid)
     * @param  int|null  $staleCount  events strictly older than the cutoff
     *                                (null when the database was unreachable)
     * @param  CarbonImmutable|null  $newestEventAt  newest performed_at in the
     *                                               table (null when empty or unreachable)
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
        public ?CarbonImmutable $newestEventAt,
        public CarbonImmutable $checkedAt,
        public ?string $reason,
    ) {}
}
