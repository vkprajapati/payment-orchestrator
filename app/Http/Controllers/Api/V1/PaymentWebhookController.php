<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\Payments\ReconcilePaymentWebhook;
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
 * details, no raw payload echoing, no secret material, and no indication
 * of whether a matching payment attempt exists.
 */
class PaymentWebhookController extends Controller
{
    public function __construct(
        private readonly PaymentWebhookManager $webhooks,
        private readonly ReconcilePaymentWebhook $reconcile,
    ) {}

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

        // Stripe verifies signatures over the RAW request body, not a
        // re-encoded JSON array. The raw body is forwarded under a
        // reserved header key for providers that need it.
        $headers['raw_body'] = $request->getContent();

        try {
            if (! $webhookProvider->verifyWebhook($payload, $headers)) {
                return response()->json(['message' => 'Invalid webhook.'], 400);
            }

            $webhookResult = $webhookProvider->parseWebhook($payload, $headers);

            if (! $webhookResult->valid) {
                return response()->json(['message' => 'Invalid webhook.'], 400);
            }

            // Reconcile local PaymentAttempt / Payment state from the
            // verified, parsed webhook. Unknown attempt IDs are ignored
            // safely (no creation) and still acknowledged generically.
            $this->reconcile->reconcile($webhookResult);
        } catch (Throwable) {
            // Never leak provider internals; never partially reconcile.
            return response()->json(['message' => 'Invalid webhook.'], 400);
        }

        return response()->json(['received' => true]);
    }
}
