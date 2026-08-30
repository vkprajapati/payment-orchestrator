<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Services\ApiKeys\ApiRequestContext;
use Illuminate\Http\JsonResponse;

class ApiContextController extends Controller
{
    /**
     * Return the merchant resolved from the authenticated API key.
     *
     * Authentication on API routes is entirely API-key based — no session,
     * no authenticated user, and no merchant ID from the request.
     */
    public function show(ApiRequestContext $context): JsonResponse
    {
        $merchant = $context->merchant();

        return response()->json([
            'merchant' => [
                'id' => $merchant->id,
                'name' => $merchant->name,
                'slug' => $merchant->slug,
            ],
        ]);
    }
}
