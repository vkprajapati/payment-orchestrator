<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Actions\Audit\PruneAuditEvents;
use App\Exceptions\InvalidAuditRetentionException;
use Illuminate\Console\Command;

/**
 * Operational audit retention: delete audit events older than the
 * configured retention window (audit.retention.days).
 *
 * Security posture:
 *   - CLI/scheduler only — there is NO HTTP endpoint for pruning and no
 *     request context is involved; retention can never be influenced by
 *     API input.
 *   - Output is aggregate-only: retention window, cutoff instant, and
 *     counts. Never merchant identifiers, event references, metadata,
 *     secrets, or any audit contents.
 *   - Invalid configuration is a controlled FAILURE: a clear error is
 *     printed, nothing is deleted, and the exit code is non-zero.
 *
 * Exit code is SUCCESS even when there is nothing to prune.
 */
final class PruneAuditEventsCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'audit:prune
        {--dry-run : Report how many events would be deleted without deleting anything}
        {--days= : Retention window override in days (must be an integer >= 1)}
        {--batch-size= : Batch size override (must be an integer >= 1)}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Delete audit events older than the configured retention window';

    /**
     * Execute the console command.
     */
    public function handle(PruneAuditEvents $prune): int
    {
        try {
            $result = $prune->execute(
                retentionDays: $this->option('days') !== null ? (int) $this->option('days') : null,
                batchSize: $this->option('batch-size') !== null ? (int) $this->option('batch-size') : null,
                dryRun: (bool) $this->option('dry-run'),
            );
        } catch (InvalidAuditRetentionException $exception) {
            // Fail safely: controlled error, nothing deleted, non-zero exit.
            $this->error($exception->getMessage());

            return Command::FAILURE;
        }

        // Aggregate-only output.
        $this->info(sprintf(
            'Audit retention window: %d day(s), cutoff %s.',
            $result->retentionDays,
            $result->cutoff->format('Y-m-d H:i:s'),
        ));

        if ($result->dryRun) {
            $this->info(sprintf(
                'Dry run: %d audit event(s) would be deleted. Nothing was deleted.',
                $result->eligible,
            ));

            return Command::SUCCESS;
        }

        $this->info(sprintf(
            'Pruned %d audit event(s) in %d batch(es).',
            $result->deleted,
            $result->batches,
        ));

        return Command::SUCCESS;
    }
}
