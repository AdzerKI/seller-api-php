<?php

namespace Opotopo\SellerApi\Webhooks;

use DateTimeImmutable;

final class PurchaseRevoked
{
    public function __construct(
        public readonly string $webhookId,
        public readonly string $purchaseId,
        public readonly string $orderId,
        public readonly string $advertisementId,
        public readonly DateTimeImmutable $revokedAt,
    ) {}

    public static function fromWebhook(string $webhookId, array $data): self
    {
        return new self(
            $webhookId,
            $data['purchaseId'],
            $data['orderId'],
            $data['advertisementId'],
            new DateTimeImmutable($data['revokedAt']),
        );
    }
}
