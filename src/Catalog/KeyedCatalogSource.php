<?php

namespace Opotopo\SellerApi\Catalog;

interface KeyedCatalogSource extends CatalogSource
{
    public function recordsOf(array $keys): iterable;
}
