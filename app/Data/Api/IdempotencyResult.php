<?php

namespace App\Data\Api;

use Illuminate\Http\JsonResponse;

/**
 * Outcome of running a mutation endpoint through the idempotency
 * orchestration layer.
 *
 * response — the final HTTP response to return: either the freshly
 *            executed operation's response, the stored replay of a
 *            previous identical request, or a controlled 409 conflict.
 * replayed — true when the response was served from a stored, previously
 *            completed reservation (never re-executed).
 *
 * Carries no secrets, no request hashes, and no internal identifiers.
 */
final readonly class IdempotencyResult
{
    public function __construct(
        public JsonResponse $response,
        public bool $replayed,
    ) {}
}
