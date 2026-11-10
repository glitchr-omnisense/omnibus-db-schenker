<?php

namespace Omnibus\DbSchenker;

use Omnibus\Exception\CarrierException;
use Omnibus\Http\Redaction;
use Omnibus\Http\Throttle;
use Omnisense\Contracts\Policy\Backoff;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface as HttpExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * DB Schenker's public tracking (eschenker.dbschenker.com, no credentials)
 * and the Schenker Open API for bookings (an API key from the partner
 * portal, which is where the account's booking profile lives).
 */
final class Api
{
    public const TRACKING = 'https://eschenker.dbschenker.com/nges-portal/api/public/tracking';
    public const BOOKING = 'https://api.dbschenker.com/booking/v1';

    public function __construct(
        private readonly HttpClientInterface $http,
        public readonly ?string $apiKey = null,
        public readonly ?string $accountNumber = null,
        private readonly int $timeout = 20,
        /** The waits the carrier asks for (omnisense/contracts' Backoff): no call while one lasts, a 429 a RateLimitedException, never retried */
        private readonly ?Backoff $backoff = null,
    ) {
    }

    /** @return array<string, mixed> */
    public function track(string $number): array
    {
        try {
            Throttle::before($this->backoff);
            $response = $this->http->request('GET', self::TRACKING, ['query' => ['refNumber' => $number], 'headers' => ['Accept' => 'application/json'], 'timeout' => $this->timeout]);
            $status = $response->getStatusCode();
            Throttle::after($this->backoff, 'db_schenker', $status, $response->getHeaders(false));
            $data = json_decode($response->getContent(false), true);
        } catch (HttpExceptionInterface $e) {
            $secrets = $this->secrets();

            throw Redaction::withoutArguments(new CarrierException('db_schenker', 'DB Schenker tracking failed: '.Redaction::text($e->getMessage(), $secrets), null, Redaction::copy($e, $secrets)));
        }
        if ($status >= 400 || !\is_array($data)) {
            throw Redaction::withoutArguments(new CarrierException('db_schenker', sprintf('DB Schenker tracking answered HTTP %d.', $status)));
        }

        return $data;
    }

    /** @return array<string, mixed> */
    public function call(string $method, string $path, ?array $body = null): array
    {
        if (!$this->apiKey || !$this->accountNumber) {
            throw Redaction::withoutArguments(new CarrierException('db_schenker', 'Bookings need the Open API key and the account number (options api_key, account_number).'));
        }
        try {
            Throttle::before($this->backoff);
            $response = $this->http->request($method, self::BOOKING.$path, [
                'headers' => ['X-API-Key' => $this->apiKey, 'Content-Type' => 'application/json', 'Accept' => 'application/json'],
                'body' => null === $body ? null : json_encode($body, \JSON_THROW_ON_ERROR),
                'timeout' => $this->timeout,
            ]);
            $status = $response->getStatusCode();
            Throttle::after($this->backoff, 'db_schenker', $status, $response->getHeaders(false));
            $data = json_decode($response->getContent(false), true);
        } catch (HttpExceptionInterface|\JsonException $e) {
            $secrets = $this->secrets();

            throw Redaction::withoutArguments(new CarrierException('db_schenker', 'DB Schenker request failed: '.Redaction::text($e->getMessage(), $secrets), null, Redaction::copy($e, $secrets)));
        }
        if (!\is_array($data)) {
            throw Redaction::withoutArguments(new CarrierException('db_schenker', sprintf('DB Schenker answered HTTP %d with a body that is not JSON.', $status)));
        }
        if ($status >= 400) {
            throw Redaction::withoutArguments(new CarrierException('db_schenker', (string) ($data['message'] ?? $data['error'] ?? $data['errors'][0]['message'] ?? sprintf('HTTP %d', $status)), isset($data['code']) ? (string) $data['code'] : null));
        }

        return $data;
    }

    /**
     * What a failure must not carry: the credentials, and what a request made of them.
     *
     * @return list<string>
     */
    private function secrets(string ...$more): array
    {
        return [(string) $this->apiKey, ...$more];
    }
}
