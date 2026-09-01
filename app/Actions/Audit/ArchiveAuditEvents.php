<?php

declare(strict_types=1);

namespace App\Actions\Audit;

use App\Exceptions\InvalidAuditRetentionException;
use App\Models\AuditEvent;
use App\Services\Audit\AuditRetention;
use Carbon\CarbonImmutable;

/**
 * Operational archival: soft-delete (archive) ACTIVE audit events older
 * than the archive cutoff.
 *
 * This is the FIRST stage of the two-stage lifecycle:
 *
 *   active → archived (deleted_at set) → permanently pruned
 *
 * Archive runs at 01:00 (audit:archive); prune runs at 02:00 (audit:prune).
 * Archiving sets deleted_at, which excludes the event from all normal
 * merchant read APIs (list/show/export/metrics/health stale counts) via
 * Laravel SoftDeletes, while preserving the row in PostgreSQL for
 * forensics. The permanent-deletion job (audit:prune) later targets only
 * archived rows whose deleted_at is older than the prune cutoff — so an
 * event archived at 01:00 is NOT eligible for permanent deletion at 02:00
 * the same day (it must wait a full additional retention window).
 *
 * Bulk operations never hydrate models: only ids are selected (pluck) and
 * each batch is soft-deleted with a single keyed UPDATE. The base query
 * carries the SoftDeletes global scope (deleted_at IS NULL), so already-
 * archived rows are naturally skipped — re-running audit:archive is
 * idempotent and never double-archives.
 *
 * Cutoff semantics: active events with performed_at STRICTLY BEFORE the
 * archive cutoff are eligible; an event exactly AT the cutoff remains
 * active. The cutoff is computed exactly once per run via
 * AuditRetention::archiveCutoff.
 *
 * The archive operation itself never creates audit events (no AuditLogger
 * usage anywhere in the read/archive path).
 */
final class ArchiveAuditEvents
{
    /**
     * Run one archive pass.
     *
     * @param  int|null  $retentionDays  overrides audit.retention.days
     * @param  int|null  $batchSize  overrides audit.retention.batch_size
     * @param  bool  $dryRun  when true, only the eligible count is
     *                        reported and nothing is archived
     *
     * @throws InvalidAuditRetentionException when the (effective) retention
     *                                        window or batch size is not a
     *                                        positive integer
     */
    public function execute(
        ?int $retentionDays = null,
        ?int $batchSize = null,
        bool $dryRun = false,
    ): ArchiveAuditEventsResult {
        $days = AuditRetention::positiveInt(
            $retentionDays ?? config('audit.retention.days'),
            'audit.retention.days',
            'retention window (days)',
        );

        $batchSize = AuditRetention::positiveInt(
            $batchSize ?? config('audit.retention.batch_size'),
            'audit.retention.batch_size',
            'batch size',
        );

        // Single archive cutoff, computed once per run (shared semantics).
        $cutoff = AuditRetention::archiveCutoff($days);

        // Active (non-archived) rows strictly older than the cutoff. The
        // SoftDeletes global scope keeps archived rows out of this count.
        $eligible = AuditEvent::query()
            ->where('performed_at', '<', $cutoff)
            ->count();

        $archived = 0;
        $batches = 0;
        $archiveTime = CarbonImmutable::instance(now());

        if (! $dryRun && $eligible > 0) {
            $lastId = 0;

            do {
                $ids = AuditEvent::query()
                    ->where('performed_at', '<', $cutoff)
                    ->where('id', '>', $lastId)
                    ->orderBy('id')
                    ->limit($batchSize)
                    ->pluck('id');

                if ($ids->isEmpty()) {
                    break;
                }

                // Soft-delete the batch: set deleted_at. The query still
                // carries the SoftDeletes scope, so already-archived rows
                // are excluded. Each batch is its own implicit transaction.
                $archived += AuditEvent::query()
                    ->whereIn('id', $ids)
                    ->update(['deleted_at' => $archiveTime]);

                $batches++;
                $lastId = (int) $ids->last();
            } while ($ids->count() === $batchSize);
        }

        return new ArchiveAuditEventsResult(
            cutoff: $cutoff,
            retentionDays: $days,
            batchSize: $batchSize,
            eligible: $eligible,
            archived: $archived,
            batches: $batches,
            dryRun: $dryRun,
        );
    }
}
