<?php

declare(strict_types=1);

namespace App\Services\Audit;

use App\Exceptions\InvalidAuditRetentionException;
use Carbon\CarbonImmutable;

/**
 * Single source of truth for audit retention configuration.
 *
 * Both the pruning action (PruneAuditEvents) and the health action
 * (GetAuditHealth) resolve the retention window and cutoff through this
 * service so the semantics can never drift: a positive-integer window
 * (env strings accepted) and a STRICT cutoff — events with performed_at
 * exactly AT the cutoff are never eligible.
 */
final class AuditRetention
{
    /**
     * The configured retention window in days, validated.
     *
     * @throws InvalidAuditRetentionException when the configuration is not
     *                                        a positive integer
     */
    public static function days(): int
    {
        return self::positiveInt(
            config('audit.retention.days'),
            'audit.retention.days',
            'retention window (days)',
        );
    }

    /**
     * The retention cutoff instant: now minus the (validated) window.
     * Computed from the caller's clock; callers that need a stable cutoff
     * invoke this once and reuse the result.
     *
     * @throws InvalidAuditRetentionException
     */
    public static function cutoff(?int $days = null): CarbonImmutable
    {
        return CarbonImmutable::instance(now()->subDays($days ?? self::days()));
    }

    /**
     * Validate a configured/overridden value as a positive integer.
     * Environment variables arrive as strings, so numeric strings are
     * accepted; anything else (zero, negative, non-numeric) fails safely.
     *
     * @throws InvalidAuditRetentionException
     */
    public static function positiveInt(mixed $value, string $configKey, string $label): int
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
