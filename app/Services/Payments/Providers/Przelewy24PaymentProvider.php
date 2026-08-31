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
 * Przelewy24 (P24) payment provider integration.
 *
 * Implements the Przelewy24 REST API (https://developers.przelewy24.pl/):
 *  - OAuth 2.0 client_credentials token acquisition (cached per request lifecycle)
 *  - Transaction registration (POST /api/v1/transaction/register)
 *  - Transaction verification (PUT /api/v1/transaction/verify)
 *  - Payment notifications (webhook verification via SHA-384 signature)
 *
 * SECURITY: API key and CRC key are never stored in the database,
 * never logged, never included in exceptions or API responses.
 */
class Przelewy24PaymentProvider implements PaymentProvider, PaymentWebhookProvider
{
    private const SANDBOX_BASE_URL = 'https://sandbox.przelewy24.pl';

    private const PRODUCTION_BASE_URL = 'https://secure.przelewy24.pl';

    private const OAUTH_ENDPOINT = '/api/v1/oauth/authorize';

    private const REGISTER_ENDPOINT = '/api/v1/transaction/register';

    private ?string $accessToken = null;

    public function name(): string
    {
        return PaymentProviderName::Przelewy24->value;
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
        $config = config('payments.providers.p24');

        return ($config['enabled'] ?? false)
            && ! empty($config['merchant_id'])
            && ! empty($config['pos_id'])
            && ! empty($config['crc_key'])
            && ! empty($config['api_key']);
    }

    private function baseUrl(): string
    {
        $environment = config('payments.providers.p24.environment', 'sandbox');

        return $environment === 'production'
            ? self::PRODUCTION_BASE_URL
            : self::SANDBOX_BASE_URL;
    }

    /**
     * Acquire an OAuth access token via client_credentials grant.
     *
     * The token is cached for the current request lifecycle to avoid
     * unnecessary token requests.
     */
    private function acquireAccessToken(): string
    {
        if ($this->accessToken !== null) {
            return $this->accessToken;
        }

        $config = config('payments.providers.p24');

        try {
            $response = Http::asForm()->post($this->baseUrl().self::OAUTH_ENDPOINT, [
                'grant_type' => 'client_credentials',
                'client_id' => $config['pos_id'],
                'client_secret' => $config['api_key'],
            ]);

            if (! $response->successful()) {
                Log::warning('P24 OAuth token acquisition failed', [
                    'status' => $response->status(),
                    'provider' => $this->name(),
                ]);

                throw PaymentProviderException::chargeFailed($this->name());
            }

            $token = $response->json('access_token');

            if (empty($token)) {
                Log::warning('P24 OAuth response missing access_token', [
                    'provider' => $this->name(),
                ]);

                throw PaymentProviderException::chargeFailed($this->name());
            }

            $this->accessToken = $token;

            return $this->accessToken;
        } catch (PaymentProviderException $e) {
            throw $e;
        } catch (\Exception $e) {
            Log::error('P24 OAuth exception', [
                'provider' => $this->name(),
                'error' => $e->getMessage(),
            ]);

            throw PaymentProviderException::chargeFailed($this->name());
        }
    }

    /**
     * Generate the P24 signature (sign field).
     *
     * P24 uses SHA-384 of pipe-delimited fields + CRC key.
     */
    private function generateSignature(string ...$fields): string
    {
        $config = config('payments.providers.p24');
        $canonical = implode('|', $fields);

        return hash('sha384', $canonical.$config['crc_key']);
    }

    public function charge(Payment $payment, array $data = []): PaymentProviderResult
    {
        if (! $this->isConfigured()) {
            throw PaymentProviderException::notConfigured($this->name());
        }

        try {
            $config = config('payments.providers.p24');
            $merchantId = $config['merchant_id'];
            $posId = $config['pos_id'];
            $sessionId = $payment->reference;
            $amount = $payment->amount;
            $currency = $payment->currency;
            $description = $payment->description ?? 'Payment '.$payment->reference;

            $signature = $this->generateSignature(
                $sessionId,
                $merchantId,
                (string) $amount,
                $currency
            );

            $payload = [
                'merchantId' => (int) $merchantId,
                'posId' => (int) $posId,
                'sessionId' => $sessionId,
                'amount' => $amount,
                'currency' => $currency,
                'description' => $description,
                'email' => $data['email'] ?? 'customer@example.com',
                'country' => $data['country'] ?? 'PL',
                'urlReturn' => $config['return_url'] ?? url('/'),
                'urlStatus' => $config['notify_url'] ?? url('/api/v1/webhooks/p24'),
                'sign' => $signature,
            ];

            $response = Http::withToken($this->acquireAccessToken())
                ->asJson()
                ->post($this->baseUrl().self::REGISTER_ENDPOINT, $payload);

            if (! $response->successful()) {
                Log::warning('P24 transaction registration failed', [
                    'status' => $response->status(),
                    'provider' => $this->name(),
                    'reference' => $payment->reference,
                ]);

                throw PaymentProviderException::chargeFailed($this->name());
            }

            $responseData = $response->json();

            // A successful registration carries status.statusCode = SUCCESS.
            // Any explicit non-SUCCESS code is a controlled provider failure.
            $statusCode = $responseData['status']['statusCode'] ?? null;

            if ($statusCode !== null && $statusCode !== 'SUCCESS') {
                Log::warning('P24 transaction registration rejected', [
                    'status' => $statusCode,
                    'provider' => $this->name(),
                    'reference' => $payment->reference,
                ]);

                throw PaymentProviderException::chargeFailed($this->name());
            }

            $orderId = $responseData['orderId'] ?? null;

            if (empty($orderId)) {
                Log::warning('P24 registration response missing orderId', [
                    'provider' => $this->name(),
                    'reference' => $payment->reference,
                ]);

                throw PaymentProviderException::chargeFailed($this->name());
            }

            $redirectUri = $responseData['redirectUri'] ?? null;

            return new PaymentProviderResult(
                provider: $this->name(),
                success: false,
                providerPaymentId: (string) $orderId,
                status: PaymentStatus::Pending->value,
                metadata: [
                    'order_id' => (string) $orderId,
                    'session_id' => $sessionId,
                    'redirect_uri' => $redirectUri,
                    'has_redirect' => $redirectUri !== null,
                ],
            );
        } catch (PaymentProviderException $e) {
            throw $e;
        } catch (\Exception $e) {
            Log::error('P24 charge exception', [
                'provider' => $this->name(),
                'reference' => $payment->reference,
                'error' => $e->getMessage(),
            ]);

            throw PaymentProviderException::chargeFailed($this->name());
        }
    }

    /**
     * {@inheritdoc}
     */
    public function verifyWebhook(array $payload, array $headers = []): bool
    {
        $required = ['merchantId', 'posId', 'sessionId', 'amount', 'currency', 'orderId', 'sign'];

        foreach ($required as $field) {
            if (empty($payload[$field])) {
                return false;
            }
        }

        $config = config('payments.providers.p24');

        if (empty($config['crc_key'])) {
            return false;
        }

        $expected = $this->generateNotificationSignature(
            (string) $payload['merchantId'],
            (string) $payload['posId'],
            (string) $payload['sessionId'],
            (string) $payload['amount'],
            (string) $payload['currency'],
            (string) $payload['orderId']
        );

        return hash_equals($expected, $payload['sign']);
    }

    /**
     * Generate the expected signature for a P24 notification.
     *
     * P24 notification sign = SHA-384(merchantId|posId|sessionId|amount|
     * currency|orderId + CRC_KEY)
     */
    private function generateNotificationSignature(
        string $merchantId,
        string $posId,
        string $sessionId,
        string $amount,
        string $currency,
        string $orderId
    ): string {
        $config = config('payments.providers.p24');

        $fields = [$merchantId, $posId, $sessionId, $amount, $currency, $orderId];

        $canonical = implode('|', $fields);

        return hash('sha384', $canonical.$config['crc_key']);
    }

    /**
     * {@inheritdoc}
     */
    public function parseWebhook(array $payload, array $headers = []): PaymentProviderWebhookResult
    {
        if (! isset($payload['orderId'])) {
            return new PaymentProviderWebhookResult(
                provider: $this->name(),
                providerPaymentId: null,
                event: 'unknown',
                status: PaymentAttemptStatus::Failed->value,
                valid: false,
            );
        }

        $orderId = (string) $payload['orderId'];
        $sessionId = $payload['sessionId'] ?? null;
        $providerStatus = $payload['status'] ?? null;
        $statusCode = $payload['status']['statusCode'] ?? $providerStatus ?? null;

        $mappedStatus = $this->mapNotificationStatus($statusCode);

        return new PaymentProviderWebhookResult(
            provider: $this->name(),
            providerPaymentId: $orderId,
            event: 'transaction.'.($statusCode ?? 'unknown'),
            status: $mappedStatus->value,
            valid: true,
            metadata: [
                'order_id' => $orderId,
                'session_id' => $sessionId,
                'status_code' => $statusCode,
                'status_description' => $payload['status']['value'] ?? null,
            ],
        );
    }

    /**
     * Map P24 notification status to application PaymentAttemptStatus.
     */
    private function mapNotificationStatus(?string $status): PaymentAttemptStatus
    {
        return match (strtoupper($status ?? '')) {
            'COMPLETED', 'SUCCESS' => PaymentAttemptStatus::Succeeded,
            'DECLINED', 'ERROR', 'CANCELED', 'CANCEL' => PaymentAttemptStatus::Failed,
            'PENDING', 'NEW', 'WAITING' => PaymentAttemptStatus::Pending,
            default => PaymentAttemptStatus::Pending,
        };
    }
}
