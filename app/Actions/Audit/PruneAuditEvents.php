<?php

declare(strict_types=1);

namespace App\Actions\Audit;

use App\Exceptions\InvalidAuditRetentionException;
use App\Models\AuditEvent;
use Carbon\CarbonImmutable;

/**
 * Operational retention for audit events.
 *
 * Deletes audit events strictly older than the configured retention
 * window (audit.retention.days), in bounded batches, targeting ONLY the
 * audit_events table. CLI/scheduler-only: there is deliberately no HTTP
 * endpoint for pruning, no request context, and no merchant identifier
 * input — retention is a global operational concern and can never be
 * influenced through the API.
 *
 * Timestamp field: the retention cutoff is applied to performed_at, the
 * documented "when the request was performed" column (AuditLogger sets it
 * to now() on every write, so it is identical in practice to created_at
 * but carries the explicit business meaning).
 *
 * Cutoff semantics: events with performed_at STRICTLY BEFORE the cutoff
 * are eligible; an event exactly AT the cutoff remains. The cutoff is
 * computed exactly once per run, so concurrent audit writes can never be
 * swept up by an unstable window — anything created after the run starts
 * is, by definition, at or after the cutoff.
 *
 * Batching: each iteration selects at most batchSize eligible ids (id
 * ascending, deterministic), deletes them with a single keyed DELETE, and
 * commits independently (no transaction spans the whole run). Only ids
 * are read (pluck) — models and their JSON metadata are never hydrated.
 * A moving id cursor gives forward progress, so rows deleted concurrently
 * between batches can never cause skips or infinite loops; delete() only
 * counts rows that actually existed, so reported counts stay accurate
 * even with concurrent pruners.
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
        $days = $this->positiveInt(
            $retentionDays ?? config('audit.retention.days'),
            'audit.retention.days',
            'retention window (days)',
        );

        $batchSize = $this->positiveInt(
            $batchSize ?? config('audit.retention.batch_size'),
            'audit.retention.batch_size',
            'batch size',
        );

        // Computed exactly once per run.
        $cutoff = CarbonImmutable::instance(now()->subDays($days));

        $eligible = AuditEvent::query()
            ->where('performed_at', '<', $cutoff)
            ->count();

        $deleted = 0;
        $batches = 0;

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

                // Each batch is its own implicit transaction (autocommit):
                // no long-running delete spans the run.
                $deleted += AuditEvent::query()
                    ->whereIn('id', $ids)
                    ->delete();

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

    /**
     * Validate a configured/overridden value as a positive integer.
     * Environment variables arrive as strings, so numeric strings are
     * accepted; anything else (zero, negative, non-numeric) fails safely.
     *
     * @throws InvalidAuditRetentionException
     */
    private function positiveInt(mixed $value, string $configKey, string $label): int
    {
        if (is_string($value) && preg_match('/^\d+$/', $value) === 1) {
            $value = (int) $value;
        }

        if (! is_int($value) || $value < 1) {
            throw new InvalidAuditRetentionException(sprintf(
                'Invalid audit retention configuration: %s must be a positive integer (at least 1) for the %s setting. Nothing was deleted.',
                $label,
                $configKey,
            ));
        }

        return $value;
    }
}
