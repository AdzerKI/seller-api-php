<?php

namespace Opotopo\SellerApi\Sync;

final class SyncReport
{
    public int $created = 0;

    public int $updated = 0;

    public int $skipped = 0;

    public int $archived = 0;

    public array $failures = [];

    public function fail(string $catalog, string $sku, string $error, ?array $details): void
    {
        $this->failures[] = [
            'catalog' => $catalog,
            'sku' => $sku,
            'error' => $error,
            'details' => $details ?? [],
        ];
    }
}
