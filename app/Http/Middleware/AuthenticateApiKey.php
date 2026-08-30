<?php

namespace App\Http\Middleware;

use App\Services\ApiKeys\ApiKeyAuthenticator;
use App\Services\ApiKeys\ApiRequestContext;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Authenticates API requests using a Bearer API key.
 *
 * Authentication failures are converted into a generic JSON 401 by
 * InvalidApiKeyException. Session-based dashboard context (CurrentMerchant)
 * is deliberately kept separate: API authentication resolves the merchant
 * from the API key itself, never from the user session.
 */
class AuthenticateApiKey
{
    public function __construct(
        protected ApiKeyAuthenticator $authenticator,
        protected ApiRequestContext $context,
    ) {}

    /**
     * Authenticate the request's Bearer token and expose the API key and
     * merchant to the rest of the application via ApiRequestContext.
     */
    public function handle(Request $request, Closure $next): Response
    {
        $this->context->set(
            $this->authenticator->authenticate($request->bearerToken()),
        );

        return $next($request);
    }
}
