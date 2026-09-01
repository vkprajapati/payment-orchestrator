<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Services\ApiKeys\ApiRequestContext;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Centralized API key scope enforcement.
 *
 * Runs AFTER api.key authentication (the ApiRequestContext is populated
 * by then) and BEFORE controllers/throttling-sensitive work. The required
 * scope(s) are declared on the route as middleware parameters, e.g.
 * `scope:payments:read` — never derived from request input.
 *
 * When multiple scopes are declared on one route they are ALTERNATIVES
 * (any-of): the request is authorized if the key holds at least one of
 * them. This supports endpoints legitimately reachable through more than
 * one permission.
 *
 * Authorization failures return a generic 403 that reveals nothing about
 * the key's actual scope set, the merchant, or the enforcement internals.
 * Authentication failures (invalid/revoked/expired keys) are handled
 * earlier by api.key and keep their generic 401.
 */
class EnsureApiKeyScope
{
    /**
     * Handle an incoming request with scope authorization.
     *
     * @param  list<string>  $scopes  route-declared alternative scopes
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next, string ...$scopes): Response
    {
        $apiKey = app(ApiRequestContext::class)->apiKey();

        // Fail closed: api.key guarantees a context, but a missing key
        // must never fall through to an accidental authorization bypass.
        if ($apiKey === null || $scopes === []) {
            return response()->json(['message' => 'Invalid API key.'], 401);
        }

        foreach ($scopes as $scope) {
            if ($apiKey->hasScope($scope)) {
                return $next($request);
            }
        }

        return response()->json(['message' => 'Forbidden.'], 403);
    }
}
