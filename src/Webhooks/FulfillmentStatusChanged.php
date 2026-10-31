<?php

namespace Opotopo\SellerApi\Webhooks;

use DateTimeImmutable;

final class FulfillmentStatusChanged
{
    public function __construct(
        public readonly string $webhookId,
        public readonly string $fulfillmentId,
        public readonly string $orderId,
        public readonly string $status,
        public readonly DateTimeImmutable $changedAt,
    ) {}

    public static function fromWebhook(string $webhookId, array $data): self
    {
        return new self(
            $webhookId,
            $data['fulfillmentId'],
            $data['orderId'],
            $data['status'],
            new DateTimeImmutable($data['changedAt']),
        );
    }
}
