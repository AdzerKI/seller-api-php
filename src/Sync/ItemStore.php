<?php

namespace Opotopo\SellerApi\Sync;

interface ItemStore
{
    public function load(string $catalog): array;

    public function save(string $catalog, ItemState $state): void;

    public function delete(string $catalog, array $keys): void;
}
