<?php

namespace Opotopo\SellerApi;

use JsonException;
use Psr\Http\Message\ResponseInterface;
use RuntimeException;

final class ApiException extends RuntimeException
{
    private const TOO_MANY_REQUESTS = 429;

    private const CONFLICT = 409;

    private const REQUEST_IN_FLIGHT = 'IDEMPOTENCY_REQUEST_IN_FLIGHT';

    public function __construct(public readonly ResponseInterface $response)
    {
        parent::__construct($response->getStatusCode().' '.$response->getReasonPhrase(), $response->getStatusCode());
    }

    public function status(): int
    {
        return $this->response->getStatusCode();
    }

    public function problem(): array
    {
        return self::decode($this->response);
    }

    public function rejected(): bool
    {
        return $this->status() >= 400 && $this->status() < 500 && ! self::transient($this->response);
    }

    public static function transient(ResponseInterface $response): bool
    {
        $status = $response->getStatusCode();

        return $status === self::TOO_MANY_REQUESTS
            || $status >= 500
            || ($status === self::CONFLICT && (self::decode($response)['details']['idempotencyKey'] ?? null) === self::REQUEST_IN_FLIGHT);
    }

    public static function decode(ResponseInterface $response): array
    {
        $body = (string) $response->getBody();
        if ($body === '') {
            return [];
        }
        try {
            $decoded = json_decode($body, true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return [];
        }

        return is_array($decoded) ? $decoded : [];
    }
}
