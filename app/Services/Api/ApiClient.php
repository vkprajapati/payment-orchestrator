<?php

namespace App\Services\Api;

use App\Exceptions\Api\ApiClientException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Centralized, safe HTTP client for the internal V1 API.
 *
 * Intended as the foundation for the V1 product UI when it consumes the
 * same key-authenticated contract used by external integrations. The client
 * is thin on purpose:
 *
 *  - Bearer API-key auth, never logged.
 *  - JSON in/out with the Laravel paginator envelope preserved as-is.
 *  - Every non-2xx response is normalized into a single ApiClientException
 *    carrying a user-safe message (no exception traces, no raw payloads)
 *    plus any validation errors for form feedback.
 *
 * The current server-rendered Blade pages query Eloquent directly through
 * domain services; this class is the seam for any page/feature that needs
 * the public API contract instead. It intentionally adds no caching, no
 * retries, and no secrets — those belong in callers.
 */
final class ApiClient
{
    public function __construct(
        private string $baseUrl = '',
        private ?string $apiKey = null,
    ) {
        $this->baseUrl = $baseUrl !== ''
            ? $baseUrl
            : (string) config('services.api.base_url');
    }

    /**
     * Return a clone authenticated with the given raw API key.
     */
    public function withKey(string $apiKey): self
    {
        $clone = clone $this;
        $clone->apiKey = $apiKey;

        return $clone;
    }

    /**
     * Perform a GET request.
     *
     * @param  array<string, mixed>  $query
     * @return array<string, mixed>
     *
     * @throws ApiClientException
     */
    public function get(string $path, array $query = []): array
    {
        return $this->request('GET', $path, ['query' => $query]);
    }

    /**
     * Perform a JSON POST request.
     *
     * @param  array<string, mixed>  $payload
     * @param  array<string, string>  $headers
     * @return array<string, mixed>
     *
     * @throws ApiClientException
     */
    public function post(
        string $path,
        array $payload = [],
        ?string $idempotencyKey = null,
        array $headers = [],
    ): array {
        if ($idempotencyKey !== null) {
            $headers['Idempotency-Key'] = $idempotencyKey;
        }

        return $this->request('POST', $path, [
            'json' => $payload,
            'headers' => $headers,
        ]);
    }

    /**
     * Execute one HTTP interaction and normalize the outcome.
     *
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     *
     * @throws ApiClientException
     */
    private function request(string $method, string $path, array $options): array
    {
        $request = Http::baseUrl($this->baseUrl)
            ->acceptJson()
            ->asJson();

        if ($this->apiKey !== null) {
            $request->withToken($this->apiKey);
        }

        if (($options['headers'] ?? []) !== []) {
            $request->withHeaders($options['headers']);
        }

        unset($options['headers']);

        try {
            $response = $request->send($method, $path, $options);
        } catch (Throwable $e) {
            // Transport-level failure (network, DNS, connection refused) —
            // never propagate the underlying exception to the user.
            throw new ApiClientException(
                message: 'Unable to reach the API. Please try again later.',
                status: 0,
                previous: $e,
            );
        }

        if ($response->failed()) {
            throw ApiClientException::fromResponse($response);
        }

        return $response->json() ?? [];
    }

    /**
     * Build a request for callers that need custom PendingRequest behavior.
     *
     * Exposes the authenticated builder so specialised callers (streaming
     * downloads, custom timeouts) can stay within the same key strategy
     * without duplicating the auth wiring.
     */
    public function builder(): PendingRequest
    {
        $request = Http::baseUrl($this->baseUrl)->acceptJson();

        if ($this->apiKey !== null) {
            $request->withToken($this->apiKey);
        }

        return $request;
    }
}
