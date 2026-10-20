<?php

namespace Opotopo\SellerApi\Files;

final class FileTarget
{
    private const SOURCE_HASH = 'sha256';

    public function __construct(
        public readonly string $sourceHash,
        public readonly string $gallery,
        public readonly string $categoryId,
    ) {}

    public static function of(FileSource $file, string $gallery, string $categoryId): self
    {
        return new self(hash(self::SOURCE_HASH, $file->source()), $gallery, $categoryId);
    }
}
