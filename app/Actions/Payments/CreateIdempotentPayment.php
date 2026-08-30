<?php

namespace App\Actions\Payments;

use App\Models\Merchant;
use App\Models\Payment;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

class CreateIdempotentPayment
{
    /**
     * PostgreSQL SQLSTATE code for a unique constraint violation.
     */
    private const UNIQUE_VIOLATION = '23505';

    public function __construct(private readonly CreatePayment $createPayment) {}

    /**
     * Create a payment, or replay an existing one when the merchant has
     * already used the given idempotency key.
     *
     * Idempotency is scoped per merchant via the database's
     * UNIQUE(merchant_id, idempotency_key) constraint.
     *
     * Version 1 behaviour: the stored key is a marker, not a request
     * fingerprint. If the same key is reused with DIFFERENT request data,
     * the original payment is returned — request bodies are not compared,
     * and no mismatch error is raised. Fingerprinting may be added in a
     * later version.
     *
     * @param  array{amount: int, currency: string, description?: string|null, idempotency_key?: string|null, metadata?: array<string, mixed>|null}  $data
     */
    public function create(Merchant $merchant, array $data, ?string $idempotencyKey): IdempotentPaymentResult
    {
        // No key: a plain creation with no deduplication semantics.
        if ($idempotencyKey === null || $idempotencyKey === '') {
            return new IdempotentPaymentResult(
                $this->createPayment->create($merchant, $data),
                created: true,
            );
        }

        // Fast path: the key was already used by this merchant.
        $existing = $this->findExisting($merchant, $idempotencyKey);

        if ($existing !== null) {
            return new IdempotentPaymentResult($existing, created: false);
        }

        $data['idempotency_key'] = $idempotencyKey;

        try {
            return DB::transaction(function () use ($merchant, $data, $idempotencyKey): IdempotentPaymentResult {
                // Re-check inside the transaction to narrow the race window.
                $existing = $this->findExisting($merchant, $idempotencyKey);

                if ($existing !== null) {
                    return new IdempotentPaymentResult($existing, created: false);
                }

                return new IdempotentPaymentResult(
                    $this->createPayment->create($merchant, $data),
                    created: true,
                );
            });
        } catch (QueryException $exception) {
            // Concurrency safety: two simultaneous requests with the same
            // key can both observe "no payment exists". The database's
            // composite unique constraint is the final arbiter — only the
            // expected unique violation is recovered from (the loser
            // replays the winner's payment); all other errors rethrow.
            if (! $this->isUniqueViolation($exception)) {
                throw $exception;
            }

            $existing = $this->findExisting($merchant, $idempotencyKey);

            if ($existing !== null) {
                return new IdempotentPaymentResult($existing, created: false);
            }

            throw $exception;
        }
    }

    /**
     * Find an existing payment for the merchant + idempotency key pair.
     */
    protected function findExisting(Merchant $merchant, string $idempotencyKey): ?Payment
    {
        return $merchant->payments()
            ->where('idempotency_key', $idempotencyKey)
            ->first();
    }

    /**
     * Determine whether the exception is a PostgreSQL unique violation.
     */
    private function isUniqueViolation(QueryException $exception): bool
    {
        return ($exception->errorInfo[0] ?? null) === self::UNIQUE_VIOLATION;
    }
}
