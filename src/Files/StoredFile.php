<?php

namespace Opotopo\SellerApi\Files;

final class StoredFile
{
    public function __construct(
        public readonly string $source,
        public readonly int $size,
        public readonly int $modifiedAt,
        public readonly string $checksum,
        public readonly string $fileId,
    ) {}
}
