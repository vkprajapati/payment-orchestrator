<?php

namespace App\Services\Payments\Providers;

use App\Contracts\Payments\PaymentProvider;
use App\Contracts\Payments\PaymentWebhookProvider;
use App\Data\Payments\PaymentProviderResult;
use App\Data\Payments\PaymentProviderWebhookResult;
use App\Enums\PaymentAttemptStatus;
use App\Enums\PaymentProviderName;
use App\Enums\PaymentStatus;
use App\Exceptions\PaymentProviderException;
use App\Models\Payment;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * PayU payment provider integration.
 *
 * Implements the PayU REST API (https://developers.payu.com/en/):
 *  - OAuth client_credentials token acquisition (cached per request lifecycle)
 *  - Order creation (POST /api/v2_1/orders)
 *  - Payment status notifications (webhook verification via second_key SHA-256 hash)
 *
 * SECURITY: client_secret and second_key are never stored in the database,
 * never logged, never included in exceptions or API responses.
 */
class PayUPaymentProvider implements PaymentProvider, PaymentWebhookProvider
{
    private const SANDBOX_BASE_URL = 'https://secure.snd.payu.com';

    private const PRODUCTION_BASE_URL = 'https://secure.payu.com';

    private const OAUTH_ENDPOINT = '/pl/standard/user/oauth/authorize';

    private const ORDERS_ENDPOINT = '/api/v2_1/orders';

    private ?string $accessToken = null;

    public function name(): string
    {
        return PaymentProviderName::PayU->value;
    }

    public function supports(string $operation): bool
    {
        if ($operation !== PaymentProvider::OPERATION_CHARGE) {
            return false;
        }

        return $this->isConfigured();
    }

    private function isConfigured(): bool
    {
        $config = config('payments.providers.payu');

        return ($config['enabled'] ?? false)
            && ! empty($config['client_id'])
            && ! empty($config['client_secret'])
            && ! empty($config['merchant_pos_id']);
    }

    private function baseUrl(): string
    {
        $environment = config('payments.providers.payu.environment', 'sandbox');

        return $environment === 'production'
            ? self::PRODUCTION_BASE_URL
            : self::SANDBOX_BASE_URL;
    }

    /**
     * Acquire an OAuth access token via client_credentials grant.
     *
     * The token is cached for the current request lifecycle to avoid
     * unnecessary token requests. PayU tokens are valid for a limited time
     * (typically 1 hour), but we acquire a fresh one per request to keep
     * the implementation simple and safe.
     */
    private function acquireAccessToken(): string
    {
        if ($this->accessToken !== null) {
            return $this->accessToken;
        }

        $config = config('payments.providers.payu');

        try {
            $response = Http::asForm()->post($this->baseUrl().self::OAUTH_ENDPOINT, [
                'grant_type' => 'client_credentials',
                'client_id' => $config['client_id'],
                'client_secret' => $config['client_secret'],
            ]);

            if (! $response->successful()) {
                Log::warning('PayU OAuth token acquisition failed', [
                    'status' => $response->status(),
                    'provider' => $this->name(),
                ]);

                throw PaymentProviderException::chargeFailed($this->name());
            }

            $token = $response->json('access_token');

            if (empty($token)) {
                Log::warning('PayU OAuth response missing access_token', [
                    'provider' => $this->name(),
                ]);

                throw PaymentProviderException::chargeFailed($this->name());
            }

            $this->accessToken = $token;

            return $token;
        } catch (PaymentProviderException $e) {
            throw $e;
        } catch (\Exception $e) {
            Log::error('PayU OAuth token acquisition exception', [
                'provider' => $this->name(),
                'error' => $e->getMessage(),
            ]);

            throw PaymentProviderException::chargeFailed($this->name());
        }
    }

    public function charge(Payment $payment, array $data = []): PaymentProviderResult
    {
        if (! $this->isConfigured()) {
            throw PaymentProviderException::notConfigured($this->name());
        }

        $config = config('payments.providers.payu');

        try {
            $accessToken = $this->acquireAccessToken();

            $orderData = $this->buildOrderRequest($payment, $config, $data);

            $response = Http::withToken($accessToken)
                ->withHeaders([
                    'Content-Type' => 'application/json',
                    'Accept' => 'application/json',
                ])
                ->post($this->baseUrl().self::ORDERS_ENDPOINT, $orderData);

            if (! $response->successful()) {
                Log::warning('PayU order creation failed', [
                    'status' => $response->status(),
                    'provider' => $this->name(),
                    'reference' => $payment->reference,
                ]);

                throw PaymentProviderException::chargeFailed($this->name());
            }

            $responseData = $response->json();

            $providerPaymentId = $responseData['orderId'] ?? null;
            $status = $responseData['status'] ?? null;
            $redirectUri = $responseData['redirectUri'] ?? null;

            $mappedStatus = $this->mapProviderStatus($status);

            return new PaymentProviderResult(
                success: $mappedStatus === PaymentStatus::Succeeded,
                provider: $this->name(),
                providerPaymentId: $providerPaymentId,
                status: $mappedStatus->value,
                metadata: [
                    'provider_status' => $status,
                    'redirect_uri' => $redirectUri,
                ],
            );
        } catch (PaymentProviderException $e) {
            throw $e;
        } catch (\Exception $e) {
            Log::error('PayU charge exception', [
                'provider' => $this->name(),
                'reference' => $payment->reference,
                'error' => $e->getMessage(),
            ]);

            throw PaymentProviderException::chargeFailed($this->name());
        }
    }

    /**
     * Build the PayU order creation request payload.
     *
     * @param  array<string, mixed>  $config
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function buildOrderRequest(Payment $payment, array $config, array $data): array
    {
        $order = [
            'merchantPosId' => $config['merchant_pos_id'],
            'description' => $payment->description ?? 'Payment '.$payment->reference,
            'currencyCode' => $payment->currency,
            'totalAmount' => (string) $payment->amount,
            'extOrderId' => $payment->reference,
            'buyer' => [
                'email' => $data['buyer']['email'] ?? 'customer@example.com',
                'firstName' => $data['buyer']['firstName'] ?? 'Customer',
                'lastName' => $data['buyer']['lastName'] ?? 'Name',
                'language' => $data['buyer']['language'] ?? 'en',
            ],
            'products' => [
                [
                    'name' => $payment->description ?? 'Payment',
                    'unitPrice' => (string) $payment->amount,
                    'quantity' => '1',
                ],
            ],
        ];

        if (! empty($config['continue_url'])) {
            $order['continueUrl'] = $config['continue_url'];
        }

        if (! empty($config['notify_url'])) {
            $order['notifyUrl'] = $config['notify_url'];
        }

        return $order;
    }

    /**
     * Map PayU order status to application PaymentStatus.
     *
     * PayU order statuses:
     * - COMPLETED → succeeded
     * - CANCELED / REJECTED → failed
     * - PENDING / NEW / WAITING_FOR_CONFIRMATION → pending
     */
    private function mapProviderStatus(?string $status): PaymentStatus
    {
        return match (strtoupper($status ?? '')) {
            'COMPLETED' => PaymentStatus::Succeeded,
            'CANCELED', 'REJECTED' => PaymentStatus::Failed,
            'PENDING', 'NEW', 'WAITING_FOR_CONFIRMATION' => PaymentStatus::Pending,
            default => PaymentStatus::Pending,
        };
    }

    // ---------------------------------------------------------------------------
    // Webhook support
    // ---------------------------------------------------------------------------

    /**
     * Verify the authenticity of a PayU webhook/notification.
     *
     * PayU signs notifications using the second_key. The signature is a
     * SHA-256 hash of the JSON payload concatenated with the second_key.
     * We verify it using a timing-safe comparison.
     */
    public function verifyWebhook(array $payload, array $headers = []): bool
    {
        $config = config('payments.providers.payu');

        if (empty($config['second_key'])) {
            Log::warning('PayU webhook verification skipped: second_key not configured', [
                'provider' => $this->name(),
            ]);

            return false;
        }

        $signature = $headers['x-openpayu-signature']
            ?? $headers['signature']
            ?? '';

        if (empty($signature)) {
            return false;
        }

        $payloadString = json_encode($payload);

        $expected = hash('sha256', $payloadString.$config['second_key']);

        return hash_equals($expected, $signature);
    }

    /**
     * Parse a PayU webhook payload into a normalized result.
     *
     * PayU notification structure:
     * {
     *   "order": {
     *     "orderId": "...",
     *     "extOrderId": "...",
     *     "orderCreateDate": "...",
     *     "status": "COMPLETED" | "PENDING" | "CANCELED" | ...
     *   }
     * }
     */
    public function parseWebhook(array $payload, array $headers = []): PaymentProviderWebhookResult
    {
        if (! is_array($payload) || ! isset($payload['order'])) {
            return new PaymentProviderWebhookResult(
                provider: $this->name(),
                providerPaymentId: null,
                event: 'unknown',
                status: null,
                valid: false,
            );
        }

        $order = $payload['order'];
        $providerPaymentId = $order['orderId'] ?? null;
        $extOrderId = $order['extOrderId'] ?? null;
        $providerStatus = $order['status'] ?? null;

        $mappedStatus = $this->mapWebhookStatus($providerStatus);

        return new PaymentProviderWebhookResult(
            provider: $this->name(),
            providerPaymentId: $providerPaymentId,
            event: 'order.'.$providerStatus,
            status: $mappedStatus->value,
            valid: true,
            metadata: [
                'provider_status' => $providerStatus,
                'external_id' => $extOrderId,
            ],
        );
    }

    /**
     * Map PayU webhook status to application PaymentAttemptStatus.
     */
    private function mapWebhookStatus(?string $status): PaymentAttemptStatus
    {
        return match (strtoupper($status ?? '')) {
            'COMPLETED' => PaymentAttemptStatus::Succeeded,
            'CANCELED', 'REJECTED' => PaymentAttemptStatus::Failed,
            'PENDING', 'NEW', 'WAITING_FOR_CONFIRMATION' => PaymentAttemptStatus::Pending,
            default => PaymentAttemptStatus::Pending,
        };
    }
}
