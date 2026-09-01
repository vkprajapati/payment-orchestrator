<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Controlled outcome category for an audit event.
 *
 * A coarse, safe categorization of how the request was resolved. Never
 * contains error details, exception messages, or stack traces — those are
 * deliberately excluded from the audit trail.
 */
enum AuditOutcome: string
{
    /** The operation completed and returned 2xx/201. */
    case Success = 'success';

    /** A controlled domain or validation failure (e.g. 409/422). */
    case Failure = 'failure';

    /** The request was rejected before any domain logic (e.g. 404). */
    case Rejected = 'rejected';
}
