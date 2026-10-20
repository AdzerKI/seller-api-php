<?php

namespace Opotopo\SellerApi\Webhooks;

use StandardWebhooks\Exception\WebhookSigningException;
use StandardWebhooks\Exception\WebhookVerificationException;
use StandardWebhooks\Webhook;

final class WebhookReceiver
{
    public const ID_HEADER = 'webhook-id';

    public const TIMESTAMP_HEADER = 'webhook-timestamp';

    public const SIGNATURE_HEADER = 'webhook-signature';

    public const HEADERS = [self::ID_HEADER, self::TIMESTAMP_HEADER, self::SIGNATURE_HEADER];

    public const EVENTS = [
        'order.purchase.created' => PurchaseCreated::class,
        'order.purchase.revoked' => PurchaseRevoked::class,
    ];

    public function __construct(
        private readonly string $secret,
        private readonly DeliveryStore $deliveries,
    ) {}

    public function receive(string $body, array $headers, callable $handler): WebhookResult
    {
        $headers = array_filter(
            array_change_key_case($headers),
            fn ($value) => is_string($value) && $value !== '',
        );
        try {
            $payload = $this->verify($body, $headers);
        } catch (WebhookVerificationException|WebhookSigningException) {
            return WebhookResult::Unauthorized;
        }
        $event = self::EVENTS[(string) ($payload['type'] ?? '')] ?? null;
        if ($event === null) {
            return WebhookResult::Ignored;
        }
        $id = $headers[self::ID_HEADER];
        if (! $this->deliveries->acquire($id)) {
            return WebhookResult::InFlight;
        }
        try {
            if (! $this->deliveries->processed($id)) {
                $handler($event::fromWebhook($id, $payload['data']));
                $this->deliveries->markProcessed($id);
            }
        } finally {
            $this->deliveries->release($id);
        }

        return WebhookResult::Handled;
    }

    // standard-webhooks/standard-webhooks#343
    private function verify(string $body, array $headers): array
    {
        $webhook = new Webhook($this->secret);
        if (isset($headers[self::ID_HEADER], $headers[self::TIMESTAMP_HEADER], $headers[self::SIGNATURE_HEADER])) {
            $expected = $webhook->sign($headers[self::ID_HEADER], $headers[self::TIMESTAMP_HEADER], $body);
            $passed = explode(' ', $headers[self::SIGNATURE_HEADER]);
            if (! array_filter($passed, fn (string $signature) => hash_equals($expected, $signature))) {
                throw new WebhookVerificationException('No matching signature found');
            }
            $headers[self::SIGNATURE_HEADER] = $expected;
        }

        return $webhook->verify($body, $headers);
    }
}
