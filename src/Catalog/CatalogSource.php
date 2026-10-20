<?php

namespace Opotopo\SellerApi\Catalog;

interface CatalogSource
{
    public function mapping(): array;

    public function records(): iterable;
}
