<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Actions\Audit\GetAuditHealth;
use Illuminate\Console\Command;

/**
 * Operational audit health check for schedulers, CI, and alerting.
 *
 * Security posture:
 *   - Read-only: never deletes or mutates audit data.
 *   - Aggregate-only output: health status, retention window, stale
 *     count, and newest-event age. Never merchant identifiers, event
 *     references, metadata, secrets, or internal exception details.
 *   - Never creates audit events (no recursion).
 *
 * Exit codes: SUCCESS when healthy, FAILURE when unhealthy — suitable
 * for cron/alerting integrations.
 */
final class CheckAuditHealthCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'audit:health
        {--json : Output the aggregate result as machine-readable JSON}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Check the operational health of the audit subsystem (read-only)';

    /**
     * Execute the console command.
     */
    public function handle(GetAuditHealth $health): int
    {
        $result = $health->execute();

        if ((bool) $this->option('json')) {
            // Machine-readable: the same strict whitelist as the API resource.
            $this->line(json_encode([
                'healthy' => $result->healthy,
                'retention_config_valid' => $result->retentionConfigValid,
                'retention_days' => $result->retentionDays,
                'stale_events' => $result->staleCount,
                'newest_event_at' => $result->newestEventAt?->toISOString(),
                'checked_at' => $result->checkedAt->toISOString(),
                'reason' => $result->reason,
            ]));
        } else {
            $this->line(sprintf(
                'Audit health: %s',
                $result->healthy ? 'HEALTHY' : 'UNHEALTHY',
            ));

            $this->line(sprintf(
                'Retention configuration: %s%s.',
                $result->retentionConfigValid ? 'valid' : 'INVALID',
                $result->retentionDays !== null ? sprintf(' (window: %d day(s))', $result->retentionDays) : '',
            ));

            $this->line(sprintf(
                'Events older than the retention cutoff: %s.',
                $result->staleCount !== null ? (string) $result->staleCount : 'unknown',
            ));

            $this->line(sprintf(
                'Newest audit event: %s.',
                $result->newestEventAt?->format('Y-m-d H:i:s') ?? 'none',
            ));

            if ($result->reason !== null) {
                $this->line(sprintf('Reason: %s.', $result->reason));
            }

            $this->line(sprintf('Checked at: %s.', $result->checkedAt->format('Y-m-d H:i:s')));
        }

        return $result->healthy ? Command::SUCCESS : Command::FAILURE;
    }
}
