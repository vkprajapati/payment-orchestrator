<?php

namespace App\Http\Middleware;

use App\Services\Merchants\CurrentMerchant;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureCurrentMerchant
{
    public function __construct(
        protected CurrentMerchant $currentMerchant,
    ) {}

    /**
     * Ensure a valid current merchant context exists before the request proceeds.
     *
     * The service validates the session's merchant ID against the authenticated
     * user's membership, clears invalid context, and selects the default merchant
     * when none is set. When the user has no merchant at all, the request simply
     * continues and the view is expected to render an empty state.
     */
    public function handle(Request $request, Closure $next): Response
    {
        $this->currentMerchant->get();

        return $next($request);
    }
}
