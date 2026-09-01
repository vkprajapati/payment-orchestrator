<?php

declare(strict_types=1);

namespace App\Actions\Audit;

use Carbon\CarbonImmutable;

/**
 * Immutable, aggregate-only summary of one pruning run. Safe to print or
 * log: contains configuration echo and counts — never merchant
 * identifiers, event references, metadata, or any audit contents.
 */
final class PruneAuditEventsResult
{
    /**
     * @param  CarbonImmutable  $cutoff  the single cutoff instant computed at
     *                                   run start; events strictly older were
     *                                   eligible for deletion
     * @param  int  $retentionDays  the retention window applied (days)
     * @param  int  $batchSize  the batch size used
     * @param  int  $eligible  rows matching the cutoff at run start (the
     *                         number a dry-run reports as "would delete")
     * @param  int  $deleted  rows actually deleted (0 in dry-run mode)
     * @param  int  $batches  delete batches committed
     * @param  bool  $dryRun  whether this run deleted anything
     */
    public function __construct(
        public readonly CarbonImmutable $cutoff,
        public readonly int $retentionDays,
        public readonly int $batchSize,
        public readonly int $eligible,
        public readonly int $deleted,
        public readonly int $batches,
        public readonly bool $dryRun,
    ) {}
}
