<?php

namespace Opotopo\SellerApi;

use Closure;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Client\NetworkExceptionInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Psr\Http\Message\StreamInterface;

class SellerApiClient
{
    public const SYNC_PATH = '/rest/v1/advertisement/seller/ads/sync';

    public const AD_PATH = '/rest/v1/advertisement/seller/ads/';

    public const UPLOAD_INITIATE_PATH = '/rest/v1/file/seller/upload/initiate';

    public const UPLOAD_COMPLETE_PATH = '/rest/v1/file/seller/upload/complete';

    public const DATA_DELIVERY_PATH = '/rest/v1/order/seller/fulfillments/%s/data-deliveries';

    public const API_KEY_HEADER = 'X-API-Key';

    public const IDEMPOTENCY_HEADER = 'Idempotency-Key';

    public const CONTENT_TYPE_HEADER = 'Content-Type';

    private const ACCEPT_HEADER = 'Accept';

    private const RETRY_AFTER_HEADER = 'Retry-After';

    private const JSON = 'application/json';

    private const OCTET_STREAM = 'application/octet-stream';

    private const NOT_FOUND = 404;

    private const MICROS_PER_MILLI = 1000;

    private const MILLIS_PER_SECOND = 1000;

    private readonly Closure $sleep;

    public function __construct(
        private readonly ClientInterface $http,
        private readonly RequestFactoryInterface $requests,
        private readonly StreamFactoryInterface $streams,
        private readonly ClientConfig $config,
        ?Closure $sleep = null,
    ) {
        $this->sleep = $sleep ?? static fn (int $millis) => usleep($millis * self::MICROS_PER_MILLI);
    }

    public function sync(array $items, string $idempotencyKey): array
    {
        return $this->json('POST', self::SYNC_PATH, array_filter([
            'items' => $items,
            'organizationId' => $this->config->organizationId,
            'publish' => $this->config->publish,
        ], fn ($value) => $value !== null), $idempotencyKey);
    }

    public function archive(string $advertisementId): void
    {
        try {
            $this->send($this->api('DELETE', self::AD_PATH.$advertisementId));
        } catch (ApiException $failure) {
            if ($failure->status() !== self::NOT_FOUND) {
                throw $failure;
            }
        }
    }

    public function initiateUpload(array $request): array
    {
        return $this->json('POST', self::UPLOAD_INITIATE_PATH, $request);
    }

    public function completeUpload(string $fileId, array $parts): void
    {
        $this->json('POST', self::UPLOAD_COMPLETE_PATH, array_filter([
            'fileId' => $fileId,
            'parts' => $parts,
        ]));
    }

    public function deliverData(string $fulfillmentId, ?string $value, ?string $replacedDeliveryId = null): array
    {
        $request = ['value' => $value, 'replacedDeliveryId' => $replacedDeliveryId];

        return $this->json(
            'POST',
            sprintf(self::DATA_DELIVERY_PATH, rawurlencode($fulfillmentId)),
            $request,
            hash('sha256', $fulfillmentId.json_encode($request, JSON_THROW_ON_ERROR)),
        );
    }

    public function put(string $url, array $headers, StreamInterface|string $content): ResponseInterface
    {
        $request = $this->requests->createRequest('PUT', $url)
            ->withHeader(self::CONTENT_TYPE_HEADER, $headers[self::CONTENT_TYPE_HEADER] ?? self::OCTET_STREAM)
            ->withBody(is_string($content) ? $this->streams->createStream($content) : $content);
        foreach ($headers as $name => $value) {
            $request = $request->withHeader($name, $value);
        }

        return $this->send($request);
    }

    private function json(string $method, string $path, array $body, ?string $idempotencyKey = null): array
    {
        $request = $this->api($method, $path)
            ->withHeader(self::CONTENT_TYPE_HEADER, self::JSON)
            ->withBody($this->streams->createStream(json_encode($body, JSON_THROW_ON_ERROR)));
        if ($idempotencyKey !== null) {
            $request = $request->withHeader(self::IDEMPOTENCY_HEADER, $idempotencyKey);
        }

        return ApiException::decode($this->send($request));
    }

    private function api(string $method, string $path): RequestInterface
    {
        return $this->requests->createRequest($method, rtrim($this->config->url, '/').$path)
            ->withHeader(self::API_KEY_HEADER, $this->config->key)
            ->withHeader(self::ACCEPT_HEADER, self::JSON);
    }

    private function send(RequestInterface $request): ResponseInterface
    {
        $times = max(1, $this->config->retryTimes);
        for ($attempt = 1; ; $attempt++) {
            $body = $request->getBody();
            if ($body->isSeekable()) {
                $body->rewind();
            }
            try {
                $response = $this->http->sendRequest($request);
            } catch (NetworkExceptionInterface $failure) {
                if ($attempt >= $times) {
                    throw $failure;
                }
                ($this->sleep)($this->config->retrySleepMs);

                continue;
            }
            $status = $response->getStatusCode();
            if ($status >= 200 && $status < 300) {
                return $response;
            }
            if ($attempt >= $times || ! ApiException::transient($response)) {
                throw new ApiException($response);
            }
            ($this->sleep)($this->pause($response));
        }
    }

    private function pause(ResponseInterface $response): int
    {
        $retryAfter = $response->getHeaderLine(self::RETRY_AFTER_HEADER);

        return is_numeric($retryAfter) ? (int) $retryAfter * self::MILLIS_PER_SECOND : $this->config->retrySleepMs;
    }
}
