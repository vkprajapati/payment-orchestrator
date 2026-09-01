<?php

declare(strict_types=1);

namespace App\Exceptions;

use RuntimeException;

/**
 * A merchant attempted to export more audit events than the configured
 * maximum (audit.export.max_events). The controller maps this to a clean
 * 422 telling the client to narrow the range — the export is never
 * silently truncated and internal row counts are never revealed.
 */
class AuditExportTooLargeException extends RuntimeException
{
    /**
     * @param  int  $maxEvents  the configured maximum, safe to surface to
     *                          the client as a documented API limit
     */
    public function __construct(int $maxEvents)
    {
        parent::__construct(sprintf(
            'Export exceeds the maximum of %d events. Narrow the export range using the available filters.',
            $maxEvents,
        ));
    }
}
