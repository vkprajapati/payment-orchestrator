<?php

namespace App\Actions\Api;

use App\Data\Api\IdempotencyResult;
use App\Enums\IdempotencyStatus;
use App\Models\IdempotencyKey;
use App\Models\Merchant;
use Closure;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use JsonException;
use Throwable;
use UnexpectedValueException;

/**
 * Database-backed idempotency orchestration for mutation API endpoints.
 *
 * Wraps an existing controller operation WITHOUT duplicating any domain
 * logic: the controller hands in a closure that runs its normal flow, and
 * this layer guarantees the closure executes at most once per
 * (merchant, key, method, path) scope.
 *
 * Flow:
 *
 *   1. No Idempotency-Key header  → the operation runs unguarded; existing
 *      API behavior is byte-for-byte unchanged.
 *   2. Reserve (short transaction): lock the scope row; a completed record
 *      replays its stored response, a hash mismatch conflicts with 409, an
 *      in-flight record conflicts with 409, otherwise a processing
 *      reservation is inserted and committed BEFORE the operation runs.
 *   3. Execute the operation with NO open transactions — provider HTTP
 *      calls never run inside a lock window.
 *   4. Complete (short transaction): store the exact response status/body.
 *
 * Failure handling:
 *
 *   - Request validation happens before this layer runs, so malformed
 *     requests never reserve a key.
 *   - Controlled domain responses (422/409/provider failures) are stored
 *     and replayed exactly like successes.
 *   - An unexpected exception releases the reservation in a fresh short
 *     transaction and rethrows — a key is never stuck in processing, and
 *     nothing is swallowed.
 *
 * The composite UNIQUE (merchant_id, key, request_method, request_path)
 * constraint is the concurrency arbiter: two simultaneous first requests
 * cannot both reserve — the loser observes the winner's processing
 * reservation and receives the controlled conflict.
 */
class HandleIdempotentRequest
{
    /**
     * PostgreSQL SQLSTATE code for a unique constraint violation.
     */
    private const UNIQUE_VIOLATION = '23505';

    /**
     * Maximum accepted Idempotency-Key header length.
     */
    private const MAX_KEY_LENGTH = 255;

    /**
     * Run the given operation idempotently for the authenticated merchant.
     *
     * @param  array<string, mixed>  $fingerprintBody  the VALIDATED request
     *                                                 body participating in the
     *                                                 request fingerprint
     */
    public function wrap(Merchant $merchant, Request $request, array $fingerprintBody, Closure $operation): IdempotencyResult
    {
        $key = $this->validatedKey($request);

        // Absent header: pass through untouched — the operation runs
        // exactly as it did before idempotency existed.
        if ($key === null) {
            return new IdempotencyResult($operation(), replayed: false);
        }

        $method = strtoupper($request->getMethod());
        $path = $this->normalizePath($request);
        $hash = $this->fingerprint($method, $path, $fingerprintBody);

        $replay = $this->reserveOrReplay($merchant, $key, $method, $path, $hash);

        if ($replay !== null) {
            return $replay;
        }

        try {
            $response = $operation();
        } catch (Throwable $exception) {
            // Release the reservation so a corrected retry is never blocked
            // forever, then rethrow — the normal error rendering takes over.
            $this->release($merchant->id, $key, $method, $path);

            throw $exception;
        }

        $this->complete($merchant->id, $key, $method, $path, $response);

        return new IdempotencyResult($response, replayed: false);
    }

    /**
     * Deterministic request fingerprint: method + normalized path +
     * canonically encoded body, hashed with SHA-256.
     *
     * Deliberately excludes the API key, authorization headers, timestamps,
     * and framework metadata. Object key ordering never affects the hash —
     * equivalent payloads canonicalize identically.
     */
    public function fingerprint(string $method, string $path, array $body): string
    {
        return hash('sha256', $method."\n".$path."\n".$this->canonicalJson($body));
    }

    /**
     * Reserve the scope, or produce the outcome for an existing record.
     *
     * Returns null when a fresh reservation was created (the caller must
     * execute the operation), or an IdempotencyResult for replay/conflict.
     */
    private function reserveOrReplay(Merchant $merchant, string $key, string $method, string $path, string $hash): ?IdempotencyResult
    {
        $attempts = 0;

        while (true) {
            try {
                return DB::transaction(function () use ($merchant, $key, $method, $path, $hash): ?IdempotencyResult {
                    $existing = $this->lockedRecord($merchant->id, $key, $method, $path);

                    if ($existing !== null) {
                        return $this->outcomeFor($existing, $hash);
                    }

                    $merchant->idempotencyKeys()->create([
                        'key' => $key,
                        'request_method' => $method,
                        'request_path' => $path,
                        'request_hash' => $hash,
                        'status' => IdempotencyStatus::Processing,
                        'locked_at' => now(),
                    ]);

                    // Reserved. The row lock is released on commit; the
                    // durable processing state protects the operation window.
                    return null;
                });
            } catch (QueryException $exception) {
                // Two simultaneous first requests can both observe "no
                // record". The composite UNIQUE constraint is the final
                // arbiter: the loser re-reads the winner's reservation and
                // receives the controlled outcome. A bounded retry also
                // covers the rare case where the winner released its
                // reservation between our failed insert and the re-read.
                if (! $this->isUniqueViolation($exception) || ++$attempts >= 2) {
                    throw $exception;
                }
            }
        }
    }

    /**
     * The controlled outcome for an already-reserved scope.
     */
    private function outcomeFor(IdempotencyKey $record, string $hash): IdempotencyResult
    {
        // Same scope, different payload: never execute, never replay —
        // this key has been used for a different logical request.
        if ($record->request_hash !== $hash) {
            return new IdempotencyResult(
                $this->conflictResponse('Idempotency key has already been used with a different request.'),
                replayed: false,
            );
        }

        // Identical request still in flight: controlled conflict instead of
        // waiting indefinitely or executing the operation a second time.
        if ($record->isProcessing()) {
            return new IdempotencyResult(
                $this->conflictResponse('An identical request is already being processed.'),
                replayed: false,
            );
        }

        // Completed: replay the exact stored response.
        return new IdempotencyResult(
            JsonResponse::fromJsonString((string) $record->response_body, (int) $record->response_status)
                ->header('Idempotent-Replayed', 'true'),
            replayed: true,
        );
    }

    /**
     * Delete a still-processing reservation after an unexpected failure.
     */
    private function release(int $merchantId, string $key, string $method, string $path): void
    {
        DB::transaction(function () use ($merchantId, $key, $method, $path): void {
            $record = $this->lockedRecord($merchantId, $key, $method, $path);

            // Never delete a completed record: its response is replayable.
            if ($record !== null && $record->isProcessing()) {
                $record->delete();
            }
        });
    }

    /**
     * Persist the final response of a successful operation.
     */
    private function complete(int $merchantId, string $key, string $method, string $path, JsonResponse $response): void
    {
        DB::transaction(function () use ($merchantId, $key, $method, $path, $response): void {
            $record = $this->lockedRecord($merchantId, $key, $method, $path);

            // Defensive: the reservation was released concurrently (the
            // operation outcome is then simply not cached).
            if ($record === null || ! $record->isProcessing()) {
                return;
            }

            $record->response_status = $response->getStatusCode();
            $record->response_body = $response->getContent();
            $record->status = IdempotencyStatus::Completed;
            $record->completed_at = now();
            $record->save();
        });
    }

    /**
     * The scope row for lookup, locked against concurrent reservers.
     */
    private function lockedRecord(int $merchantId, string $key, string $method, string $path): ?IdempotencyKey
    {
        return IdempotencyKey::query()
            ->where('merchant_id', $merchantId)
            ->where('key', $key)
            ->where('request_method', $method)
            ->where('request_path', $path)
            ->lockForUpdate()
            ->first();
    }

    /**
     * A controlled conflict response that reveals nothing about the
     * stored record (no hashes, no identifiers, no metadata).
     */
    private function conflictResponse(string $message): JsonResponse
    {
        return response()->json(['message' => $message], 409);
    }

    /**
     * The validated Idempotency-Key header value, or null when the header
     * was not sent. Present-but-invalid values are rejected with 422.
     */
    private function validatedKey(Request $request): ?string
    {
        if (! $request->headers->has('Idempotency-Key')) {
            return null;
        }

        $key = trim((string) $request->headers->get('Idempotency-Key'));

        if ($key === '' || mb_strlen($key) > self::MAX_KEY_LENGTH) {
            throw ValidationException::withMessages([
                'idempotency_key' => ['The idempotency key must be a non-empty string of at most 255 characters.'],
            ]);
        }

        return $key;
    }

    /**
     * The normalized request path (no query string, no trailing slash).
     */
    private function normalizePath(Request $request): string
    {
        return '/'.trim($request->getPathInfo(), '/');
    }

    /**
     * Deterministic canonical JSON encoding for fingerprinting.
     */
    private function canonicalJson(array $body): string
    {
        try {
            return json_encode(
                $this->canonicalize($body),
                JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES,
            );
        } catch (JsonException $exception) {
            // A body that cannot be canonically encoded must never produce
            // an unstable hash — fail loudly instead.
            throw new UnexpectedValueException('Request body could not be canonicalized for idempotency.', 0, $exception);
        }
    }

    /**
     * Recursively sort arrays by key so equivalent payloads (different JSON
     * key ordering) encode to identical text. Key–value pairs are preserved,
     * so distinct payloads always produce distinct canonical forms.
     */
    private function canonicalize(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }

        $canonical = [];

        foreach ($value as $arrayKey => $arrayValue) {
            $canonical[$arrayKey] = $this->canonicalize($arrayValue);
        }

        ksort($canonical, SORT_STRING);

        return $canonical;
    }

    /**
     * Determine whether the exception is a PostgreSQL unique violation.
     */
    private function isUniqueViolation(QueryException $exception): bool
    {
        return ($exception->errorInfo[0] ?? null) === self::UNIQUE_VIOLATION;
    }
}
