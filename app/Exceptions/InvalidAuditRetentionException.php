<?php

declare(strict_types=1);

namespace App\Exceptions;

use InvalidArgumentException;

/**
 * Thrown when the audit retention configuration is unusable — a retention
 * window or batch size that is not a positive integer (zero, negative,
 * or non-numeric). Failing safely here means NOTHING is ever deleted
 * with an ambiguous configuration: the prune command reports a controlled
 * error and exits non-zero.
 */
class InvalidAuditRetentionException extends InvalidArgumentException {}
