<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Centralized allow-list of API key scopes.
 *
 * Every API key carries an explicit set of these scopes; authorization is
 * enforced centrally by the `scope` middleware before controllers run —
 * never inferred from request input. Scope strings use a
 * "domain:action" grouping.
 *
 * account:read covers the identity endpoint (GET /me): it is the minimal
 * "who am I" call every integration performs and deliberately does not
 * imply access to any domain data.
 */
enum ApiKeyScope: string
{
    /** Identity endpoint: GET /api/v1/me. */
    case AccountRead = 'account:read';

    /** List and retrieve payments. */
    case PaymentsRead = 'payments:read';

    /** Create payments. */
    case PaymentsWrite = 'payments:write';

    /** Process payments and create/execute payment attempts. */
    case PaymentsProcess = 'payments:process';

    /** List and retrieve refunds. */
    case RefundsRead = 'refunds:read';

    /** Create refunds. */
    case RefundsWrite = 'refunds:write';

    /** List and retrieve API keys (metadata only — never secrets). */
    case ApiKeysRead = 'api_keys:read';

    /** Create, revoke, and rotate API keys. */
    case ApiKeysWrite = 'api_keys:write';

    /** List, retrieve, export, and aggregate audit events. */
    case AuditRead = 'audit:read';

    /**
     * All supported scope values, in declaration order. Used for the
     * full-access default and the migration backfill.
     *
     * @return list<string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }

    /**
     * Whether the given string is a valid scope value.
     */
    public static function isValid(string $value): bool
    {
        return self::tryFrom($value) !== null;
    }
}
