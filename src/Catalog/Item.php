<?php

namespace Opotopo\SellerApi\Catalog;

use Opotopo\SellerApi\Files\FileSource;

final class Item
{
    public function __construct(
        public readonly string $key,
        public readonly string $sku,
        public readonly ?string $categoryId,
        public readonly array $payload,
        public readonly array $imageFiles,
        public readonly ?FileSource $secondImageFile,
        public readonly array $variantFiles,
        public readonly string $fingerprint,
        public readonly ?ItemFailure $failure = null,
    ) {}

    public static function failed(string $key, string $sku, ItemFailure $failure): self
    {
        return new self($key, $sku, null, [], [], null, [], '', $failure);
    }
}
