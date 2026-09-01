<?php

declare(strict_types=1);

namespace App\Actions\Audit;

use Carbon\CarbonImmutable;

/**
 * Immutable, aggregate-only summary of one archive run. Safe to print or
 * log: contains configuration echo and counts — never merchant
 * identifiers, event references, metadata, or any audit contents.
 */
final class ArchiveAuditEventsResult
{
    /**
     * @param  CarbonImmutable  $cutoff  the single archive-cutoff instant
     *                                   computed at run start; active events
     *                                   with performed_at strictly older were
     *                                   eligible for archival
     * @param  int  $retentionDays  the retention window applied (days)
     * @param  int  $batchSize  the batch size used
     * @param  int  $eligible  active rows matching the cutoff at run start
     *                         (what a dry-run reports as "would archive")
     * @param  int  $archived  rows actually archived (0 in dry-run mode)
     * @param  int  $batches  archive batches committed
     * @param  bool  $dryRun  whether this run archived anything
     */
    public function __construct(
        public readonly CarbonImmutable $cutoff,
        public readonly int $retentionDays,
        public readonly int $batchSize,
        public readonly int $eligible,
        public readonly int $archived,
        public readonly int $batches,
        public readonly bool $dryRun,
    ) {}
}
