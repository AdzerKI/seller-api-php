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
        'order.fulfillment.status.changed' => FulfillmentStatusChanged::class,
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
            $payload = (new Webhook($this->secret))->verify($body, $headers);
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
}
