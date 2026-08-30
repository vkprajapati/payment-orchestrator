<?php

namespace App\Http\Controllers\Api\V1;

use App\Exceptions\PaymentProviderException;
use App\Http\Controllers\Controller;
use App\Services\Payments\PaymentWebhookManager;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Throwable;

/**
 * Provider webhook entry point.
 *
 * SECURITY: this endpoint is intentionally NOT protected by api.key —
 * callers are external payment providers, not merchant clients.
 * Authentication is the provider-specific verification performed by the
 * webhook manager.
 *
 * Responses are deliberately generic: unknown providers get a 404,
 * verification failures get a 400 with the same body — no verification
 * details, no raw payload echoing, no secret material.
 */
class PaymentWebhookController extends Controller
{
    public function __construct(private readonly PaymentWebhookManager $webhooks) {}

    public function handle(Request $request, string $provider): JsonResponse
    {
        try {
            $webhookProvider = $this->webhooks->resolve($provider);
        } catch (PaymentProviderException) {
            // Unknown provider: controlled 404 that reveals nothing.
            return response()->json(['message' => 'Not found.'], 404);
        }

        $payload = $request->json()->all();
        $headers = array_map(
            fn (array $values): string => $values[0],
            $request->headers->all(),
        );

        try {
            if (! $webhookProvider->verifyWebhook($payload, $headers)) {
                return response()->json(['message' => 'Invalid webhook.'], 400);
            }

            $webhookProvider->parseWebhook($payload, $headers);
        } catch (Throwable) {
            // Never leak provider internals; never update payments here.
            return response()->json(['message' => 'Invalid webhook.'], 400);
        }

        // Architecture step only: the parsed result is intentionally
        // discarded — no payment status updates, no webhook persistence.
        return response()->json(['received' => true]);
    }
}
