<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Services\ApiKeys\ApiRequestContext;
use Closure;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Symfony\Component\HttpFoundation\Response;

/**
 * Merchant-aware API rate limiting.
 *
 * Applied to authenticated API routes as `throttle:bucket` (e.g.
 * `throttle:standard`, `throttle:sensitive`). The bucket name maps to
 * a RateLimiter::for() callback registered in AppServiceProvider that
 * returns a Limit object with values from config.
 *
 * Rate limiter key strategy:
 *
 *   Authenticated   -> api:merchant:{id}:{bucket}   (tenant isolation)
 *   Unauthenticated -> api:ip:{address}:{bucket}    (conservative, no leak)
 *
 * Authentication (api.key middleware) runs BEFORE this middleware, so a
 * valid merchant context is always available. Invalid/missing API keys
 * never consume a valid merchant's bucket -- they fall to the IP-based key.
 *
 * Response on limit exceeded: 429 with a generic message, Retry-After and
 * X-RateLimit-* headers. No internal identifiers or limiter internals
 * are exposed.
 *
 * This middleware runs BEFORE idempotency (which lives inside controllers)
 * so rate limiting cannot bypass authentication. Idempotent replays still
 * reach the idempotency layer inside the controller, which correctly returns
 * the stored response. The two layers are orthogonal and compose safely.
 */
class ThrottleApiRequests
{
    /**
     * Handle an incoming request with merchant-aware rate limiting.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next, string $bucket = 'standard'): Response
    {
        $limit = $this->resolveLimit($bucket);
        $key = $this->resolveLimiterKey($request, $bucket);

        if ($limit === null) {
            return $next($request);
        }

        if (RateLimiter::tooManyAttempts($key, $limit->maxAttempts)) {
            return $this->response($bucket);
        }

        RateLimiter::hit($key, $limit->decaySeconds);

        $response = $next($request);

        if ($response instanceof Response) {
            // headers->add() (not withHeaders()) so streamed responses such
            // as CSV exports also receive rate-limit headers.
            $response->headers->add([
                'X-RateLimit-Limit' => $limit->maxAttempts,
                'X-RateLimit-Remaining' => RateLimiter::remaining($key, $limit->maxAttempts),
            ]);
        }

        return $response;
    }

    /**
     * Resolve the RateLimiter Limit for the requested bucket.
     */
    private function resolveLimit(string $bucket): ?Limit
    {
        $callback = RateLimiter::limiter($bucket);

        if ($callback === null) {
            return null;
        }

        return $callback(request(), $bucket);
    }

    /**
     * Resolve the rate-limit identity for the current request.
     */
    private function resolveLimiterKey(Request $request, string $bucket): string
    {
        $context = app(ApiRequestContext::class);

        if ($context->has()) {
            $merchant = $context->merchant();

            if ($merchant !== null) {
                return 'api:merchant:'.$merchant->id.':'.$bucket;
            }
        }

        return 'api:ip:'.($request->ip() ?? 'unknown').':'.$bucket;
    }

    /**
     * Return the 429 rate-limited response.
     */
    private function response(string $bucket): Response
    {
        $config = config("rate_limiting.buckets.{$bucket}");
        $decayMinutes = is_array($config) ? ($config['decay_minutes'] ?? 1) : 1;

        return response()->json(
            ['message' => 'Too Many Attempts. Please slow down.'],
            429,
        )->withHeaders([
            'Retry-After' => $decayMinutes * 60,
            'X-RateLimit-Remaining' => 0,
        ]);
    }
}
