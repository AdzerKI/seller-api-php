<?php

namespace Opotopo\SellerApi\Webhooks;

use DateTimeImmutable;

final class PurchaseCreated
{
    public function __construct(
        public readonly string $webhookId,
        public readonly string $purchaseId,
        public readonly string $orderId,
        public readonly ?string $fulfillmentId,
        public readonly string $advertisementId,
        public readonly ?int $advertisementNumber,
        public readonly ?string $variantId,
        public readonly int $quantity,
        public readonly int|float $amount,
        public readonly string $delivery,
        public readonly DateTimeImmutable $purchasedAt,
    ) {}

    public static function fromWebhook(string $webhookId, array $data): self
    {
        return new self(
            $webhookId,
            $data['purchaseId'],
            $data['orderId'],
            $data['fulfillmentId'] ?? null,
            $data['advertisementId'],
            $data['advertisementNumber'] ?? null,
            $data['variantId'] ?? null,
            $data['quantity'],
            $data['amount'],
            $data['delivery'],
            new DateTimeImmutable($data['purchasedAt']),
        );
    }
}
