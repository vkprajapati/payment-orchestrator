<?php

declare(strict_types=1);

namespace App\Actions\Audit;

use App\Exceptions\InvalidAuditRetentionException;
use App\Models\AuditEvent;
use App\Services\Audit\AuditRetention;

/**
 * Operational retention for audit events.
 *
 * Permanently prunes ARCHIVED audit events only. This is the second
 * stage of the two-stage lifecycle:
 *
 *   active → archived (deleted_at set) → permanently pruned (force deleted)
 *
 * Archive runs at 01:00 (audit:archive); prune runs at 02:00 (audit:prune).
 * Because pruning targets deleted_at — the archive timestamp — an event
 * archived at 01:00 today is NOT prunable at 02:00 today: its deleted_at
 * is in the recent past, far newer than (now - retention_days). It is
 * eligible for permanent deletion only after a full additional retention
 * window has elapsed, which guarantees the archive→prune grace window and
 * prevents an archived event from being destroyed immediately.
 *
 * Pruning NEVER touches active (non-archived) events — only soft-deleted
 * rows older than the prune cutoff are force-deleted.
 *
 * CLI/scheduler-only: there is deliberately no HTTP endpoint for pruning,
 * no request context, and no merchant identifier input — retention is a
 * global operational concern and can never be influenced through the API.
 *
 * Cutoff semantics: archived events with deleted_at STRICTLY BEFORE the
 * cutoff are eligible; an archived event exactly AT the cutoff remains.
 * The cutoff is computed exactly once per run, so concurrent audit writes
 * can never be swept up by an unstable window.
 *
 * Batching: each iteration selects at most batchSize eligible ids (id
 * ascending, deterministic), permanently deletes them with a single keyed
 * FORCE DELETE, and commits independently (no transaction spans the run).
 * Only ids are read (pluck) — models and their JSON metadata are never
 * hydrated. A moving id cursor gives forward progress, so rows deleted
 * concurrently between batches can never cause skips or infinite loops;
 * forceDelete() only counts rows that actually existed, so reported counts
 * stay accurate even with concurrent pruners.
 *
 * The prune operation itself never creates audit events (no AuditLogger
 * usage anywhere in the read/delete path).
 */
final class PruneAuditEvents
{
    /**
     * Run one pruning pass.
     *
     * @param  int|null  $retentionDays  overrides audit.retention.days
     * @param  int|null  $batchSize  overrides audit.retention.batch_size
     * @param  bool  $dryRun  when true, only the eligible count is
     *                        reported and nothing is deleted
     *
     * @throws InvalidAuditRetentionException when the (effective) retention
     *                                        window or batch size is not a
     *                                        positive integer
     */
    public function execute(
        ?int $retentionDays = null,
        ?int $batchSize = null,
        bool $dryRun = false,
    ): PruneAuditEventsResult {
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

        // Computed exactly once per run (shared cutoff semantics).
        $cutoff = AuditRetention::cutoff($days);

        // Target ONLY archived rows (deleted_at IS NOT NULL) whose archive
        // time is strictly older than the prune cutoff. The cutoff is
        // expressed against deleted_at (not performed_at) so that events
        // archived at 01:00 are never prunable at 02:00 the same day.
        $eligible = AuditEvent::query()
            ->onlyTrashed()
            ->where('deleted_at', '<', $cutoff)
            ->count();

        $deleted = 0;
        $batches = 0;

        if (! $dryRun && $eligible > 0) {
            $lastId = 0;

            do {
                $ids = AuditEvent::query()
                    ->onlyTrashed()
                    ->where('deleted_at', '<', $cutoff)
                    ->where('id', '>', $lastId)
                    ->orderBy('id')
                    ->limit($batchSize)
                    ->pluck('id');

                if ($ids->isEmpty()) {
                    break;
                }

                // forceDelete() permanently removes archived rows. Each
                // batch is its own implicit transaction (autocommit): no
                // long-running delete spans the run.
                $deleted += AuditEvent::query()
                    ->whereIn('id', $ids)
                    ->forceDelete();

                $batches++;
                $lastId = (int) $ids->last();
            } while ($ids->count() === $batchSize);
        }

        return new PruneAuditEventsResult(
            cutoff: $cutoff,
            retentionDays: $days,
            batchSize: $batchSize,
            eligible: $eligible,
            deleted: $deleted,
            batches: $batches,
            dryRun: $dryRun,
        );
    }
}
