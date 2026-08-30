<?php

namespace App\Exceptions;

use Exception;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Thrown when a request presents an API key that cannot be authenticated.
 *
 * The renderer deliberately returns a generic message so callers cannot
 * distinguish between an unknown key, a wrong secret, a revoked key, an
 * expired key, or a malformed token. Raw API keys are never included in
 * the message or in the response.
 */
final class InvalidApiKeyException extends Exception
{
    /**
     * The single public error message used for every authentication failure.
     */
    public const MESSAGE = 'Invalid API key.';

    public function __construct()
    {
        parent::__construct(self::MESSAGE);
    }

    /**
     * Render a consistent JSON 401 response, regardless of the failure reason.
     */
    public function render(Request $request): JsonResponse
    {
        return response()->json([
            'message' => self::MESSAGE,
        ], 401);
    }
}
