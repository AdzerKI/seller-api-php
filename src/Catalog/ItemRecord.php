<?php

namespace Opotopo\SellerApi\Catalog;

use Opotopo\SellerApi\Files\FileSource;

interface ItemRecord
{
    public function key(): string;

    public function value(string $path): mixed;

    public function file(mixed $entry): FileSource;
}
