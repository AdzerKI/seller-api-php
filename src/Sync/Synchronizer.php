<?php

namespace Opotopo\SellerApi\Sync;

use DateTimeImmutable;
use InvalidArgumentException;
use Opotopo\SellerApi\ApiException;
use Opotopo\SellerApi\Catalog\CatalogSource;
use Opotopo\SellerApi\Catalog\Item;
use Opotopo\SellerApi\Catalog\ItemBuilder;
use Opotopo\SellerApi\Catalog\ItemFailure;
use Opotopo\SellerApi\Files\FileUploader;
use Opotopo\SellerApi\SellerApiClient;

final class Synchronizer
{
    public const FILES_UNAVAILABLE = 'FILES_UNAVAILABLE';

    private const FILE_IDS_DETAIL = 'fileIds';

    private const SCAN_REJECTIONS_DETAIL = 'scanRejections';

    private const FILE_IDS_SEPARATOR = ',';

    private const REASON_SEPARATOR = ':';

    private readonly ItemBuilder $builder;

    public function __construct(
        private readonly SellerApiClient $client,
        private readonly FileUploader $files,
        private readonly ItemStore $store,
        private readonly int $batch,
    ) {
        $this->builder = new ItemBuilder;
    }

    public function run(array $catalogs, ?string $only = null, bool $full = false): SyncReport
    {
        if ($only !== null && ! isset($catalogs[$only])) {
            throw new InvalidArgumentException($only);
        }
        $report = new SyncReport;
        foreach ($catalogs as $name => $catalog) {
            if ($only === null || $only === (string) $name) {
                $this->catalog((string) $name, $catalog, $full, $report);
            }
        }

        return $report;
    }

    private function catalog(string $name, CatalogSource $catalog, bool $full, SyncReport $report): void
    {
        $known = $this->store->load($name);
        $mapping = $catalog->mapping();
        $seen = [];
        $pending = [];
        $batch = max(1, $this->batch);
        foreach ($catalog->records() as $record) {
            $item = $this->builder->build($record, $mapping);
            $seen[$item->key] = true;
            $state = $known[$item->key] ?? null;
            if ($item->failure !== null) {
                $this->failed($name, $item, $known, $report, $item->failure);

                continue;
            }
            if (! $full && $state !== null && $state->error === null && $state->fingerprint === $item->fingerprint) {
                $report->skipped++;

                continue;
            }
            $pending[] = $item;
            if (count($pending) >= $batch) {
                $this->send($name, $pending, $known, $report, true);
                $pending = [];
            }
        }
        if ($pending !== []) {
            $this->send($name, $pending, $known, $report, true);
        }
        $gone = array_diff_key($known, $seen);
        if ($gone === []) {
            return;
        }
        foreach ($gone as $state) {
            if ($state->advertisementId !== null) {
                $this->client->archive($state->advertisementId);
            }
        }
        $this->store->delete($name, array_values(array_map(fn (ItemState $state) => $state->key, $gone)));
        $report->archived += count($gone);
    }

    private function send(string $catalog, array $items, array &$known, SyncReport $report, bool $reupload): void
    {
        $ready = [];
        $payloads = [];
        $sources = [];
        foreach ($items as $item) {
            try {
                $payloads[] = $this->payload($item, $sources);
                $ready[] = $item;
            } catch (ItemFailure $failure) {
                $this->failed($catalog, $item, $known, $report, $failure);
            }
        }
        if ($ready === []) {
            return;
        }
        try {
            $response = $this->client->sync($payloads, self::idempotencyKey());
        } catch (ApiException $failure) {
            if (! $failure->rejected()) {
                throw $failure;
            }
            if (count($ready) > 1) {
                foreach ($ready as $item) {
                    $this->send($catalog, [$item], $known, $report, $reupload);
                }

                return;
            }
            $this->failed($catalog, $ready[0], $known, $report, ItemFailure::fromApi($failure));

            return;
        }
        $retry = [];
        foreach ($response['results'] ?? [] as $index => $result) {
            $item = $ready[$index];
            $result = self::namedScanRejections($result, $sources);
            $error = $result['error'] ?? null;
            if ($error === self::FILES_UNAVAILABLE && $reupload) {
                $this->files->forget(explode(
                    self::FILE_IDS_SEPARATOR,
                    (string) ($result['errorDetails'][self::FILE_IDS_DETAIL] ?? ''),
                ));
                $retry[] = $item;

                continue;
            }
            $this->applied($catalog, $item, $result, $known, $report);
        }
        if ($retry !== []) {
            $this->send($catalog, $retry, $known, $report, false);
        }
    }

    /**
     * @param  array<string, string>  $sources
     */
    private function payload(Item $item, array &$sources): array
    {
        $payload = $item->payload;
        if ($item->imageFiles !== []) {
            $payload['imageFileIds'] = $this->upload($item->imageFiles, FileUploader::MAIN, $item, $sources);
        }
        if ($item->secondImageFile !== null) {
            $payload['secondImageFileId'] = $this->upload([$item->secondImageFile], FileUploader::MAIN, $item, $sources)[0];
        }
        foreach ($item->variantFiles as $index => $files) {
            if ($files !== []) {
                $payload['variants'][$index]['fileIds'] = $this->upload($files, FileUploader::PROJECT, $item, $sources);
            }
        }

        return $payload;
    }

    /**
     * @param  array<string, string>  $sources
     */
    private function upload(array $files, string $gallery, Item $item, array &$sources): array
    {
        $fileIds = [];
        foreach ($files as $file) {
            $fileId = $this->files->upload($file, $gallery, $item->categoryId);
            $sources[$fileId] = $file->source();
            $fileIds[] = $fileId;
        }

        return $fileIds;
    }

    /**
     * @param  array<string, string>  $sources
     */
    private static function namedScanRejections(array $result, array $sources): array
    {
        $named = $result['errorDetails'][self::SCAN_REJECTIONS_DETAIL] ?? null;
        if (! is_string($named) || $named === '') {
            return $result;
        }
        $reasons = [];
        foreach (explode(self::FILE_IDS_SEPARATOR, $named) as $pair) {
            [$fileId, $reason] = explode(self::REASON_SEPARATOR, $pair, 2) + [1 => ''];
            $reasons[$sources[$fileId] ?? $fileId] = $reason;
        }
        $result['errorDetails'][self::SCAN_REJECTIONS_DETAIL] = $reasons;

        return $result;
    }

    private function applied(string $catalog, Item $item, array $result, array &$known, SyncReport $report): void
    {
        $state = $known[$item->key] ?? null;
        $advertisementId = $result['advertisementId'] ?? null;
        if ($advertisementId !== null && $state?->advertisementId !== null && $state->advertisementId !== $advertisementId) {
            $this->client->archive($state->advertisementId);
        }
        $error = $result['error'] ?? null;
        $details = $error === null ? null : ($result['errorDetails'] ?? null);
        $this->save($catalog, $known, new ItemState(
            $item->key,
            $item->sku,
            $advertisementId ?? $state?->advertisementId,
            $item->fingerprint,
            $error,
            $details,
            $error === null ? new DateTimeImmutable : $state?->syncedAt,
        ));
        if (isset($result['packageGitSource'])) {
            $report->gitSource($catalog, $item->sku, $result['packageGitSource']);
        }
        if ($error !== null) {
            $report->fail($catalog, $item->sku, $error, $details);
        } elseif ($result['created'] ?? false) {
            $report->created++;
        } else {
            $report->updated++;
        }
    }

    private function failed(string $catalog, Item $item, array &$known, SyncReport $report, ItemFailure $failure): void
    {
        $state = $known[$item->key] ?? null;
        $this->save($catalog, $known, new ItemState(
            $item->key,
            $item->sku,
            $state?->advertisementId,
            $state?->fingerprint,
            $failure->error,
            $failure->details ?: null,
            $state?->syncedAt,
        ));
        $report->fail($catalog, $item->sku, $failure->error, $failure->details);
    }

    private function save(string $catalog, array &$known, ItemState $state): void
    {
        $this->store->save($catalog, $state);
        $known[$state->key] = $state;
    }

    private static function idempotencyKey(): string
    {
        $bytes = random_bytes(16);
        $bytes[6] = chr(ord($bytes[6]) & 0x0F | 0x40);
        $bytes[8] = chr(ord($bytes[8]) & 0x3F | 0x80);

        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($bytes), 4));
    }
}
