<?php

namespace Opotopo\SellerApi\Sync;

interface ItemStore
{
    public const LISTED_FAILURES = 100;

    public function load(string $catalog): array;

    public function save(string $catalog, ItemState $state): void;

    public function delete(string $catalog, array $keys): void;
}
