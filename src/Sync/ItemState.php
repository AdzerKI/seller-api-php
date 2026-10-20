<?php

namespace Opotopo\SellerApi\Sync;

use DateTimeImmutable;

final class ItemState
{
    public function __construct(
        public readonly string $key,
        public readonly string $sku,
        public readonly ?string $advertisementId,
        public readonly ?string $fingerprint,
        public readonly ?string $error,
        public readonly ?array $errorDetails,
        public readonly ?DateTimeImmutable $syncedAt,
    ) {}
}
