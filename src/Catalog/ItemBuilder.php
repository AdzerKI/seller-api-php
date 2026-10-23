<?php

namespace Opotopo\SellerApi\Catalog;

use BackedEnum;
use Opotopo\SellerApi\Files\FileSource;
use SplFileInfo;
use Stringable;
use Traversable;

final class ItemBuilder
{
    private const URL_SCHEMES = ['http://', 'https://'];

    private const NUMERIC_FIELDS = ['price', 'quantity', 'updateMonths'];

    private const ORIENTATION_AXES = ['rollDeg', 'pitchDeg', 'yawDeg'];

    private const YES = 'да';

    private const NO = 'нет';

    private const REQUIRED = 'REQUIRED';

    private const HASH = 'sha256';

    public function build(ItemRecord $record, array $mapping): Item
    {
        $key = $record->key();
        $payload = [];
        foreach ($mapping['fields'] ?? [] as $field => $path) {
            $value = self::field($field, self::value($record, $path));
            if ($value !== null) {
                $payload[$field] = $value;
            }
        }
        $sku = (string) ($payload['sku'] ?? '');

        try {
            return self::item($record, $mapping, $key, $sku, $payload);
        } catch (ItemFailure $failure) {
            return Item::failed($key, $sku, $failure);
        }
    }

    private static function item(ItemRecord $record, array $mapping, string $key, string $sku, array $payload): Item
    {
        $categoryId = self::text(self::value($record, $mapping['category'] ?? null));
        if ($categoryId !== null) {
            $payload['categoryId'] = $categoryId;
        }

        $attributes = [];
        foreach ($mapping['attributes'] ?? [] as $name => $path) {
            $value = self::text(self::value($record, $path));
            if ($value !== null) {
                $attributes[$name] = $value;
            }
        }
        if ($attributes !== []) {
            $payload['attributes'] = $attributes;
        }

        $roles = $mapping['images'] ?? [];
        $cover = array_slice(self::media($record, $roles['cover'] ?? null), 0, 1);
        $second = self::media($record, $roles['second'] ?? null)[0] ?? null;
        $images = self::unique(array_merge(
            $cover,
            self::media($record, $roles['gallery'] ?? null),
            $second === null ? [] : [$second],
        ));
        $imageFiles = array_values(array_filter($images, fn ($image) => $image instanceof FileSource));
        $imageUrls = array_values(array_filter($images, 'is_string'));
        if ($imageFiles !== [] && $cover !== [] && is_string($cover[0])) {
            throw new ItemFailure(ItemFailure::COVER_URL_AFTER_FILES, ['cover' => $cover[0]]);
        }
        if ($imageUrls !== []) {
            $payload['imageUrls'] = $imageUrls;
        }
        if (is_string($second)) {
            $payload['secondImageUrl'] = $second;
        }

        $model3d = $mapping['model3d'] ?? [];
        $model3dUrl = self::text(self::value($record, $model3d['url'] ?? null));
        if ($model3dUrl !== null) {
            $payload['model3dUrl'] = $model3dUrl;
            $orientation = self::orientation(self::value($record, $model3d['orientation'] ?? null));
            if ($orientation !== null) {
                $payload['model3dOrientation'] = $orientation;
            }
        }

        $package = $mapping['package'] ?? [];
        $repositoryPackage = array_filter([
            'manager' => self::text(self::value($record, $package['manager'] ?? null)),
            'name' => self::text(self::value($record, $package['name'] ?? null)),
        ], fn ($value) => $value !== null);
        if ($repositoryPackage !== []) {
            $payload['repositoryPackage'] = $repositoryPackage;
        }
        $repositoryUrl = self::text(self::value($record, $package['git'] ?? null));
        if ($repositoryUrl !== null) {
            $payload['packageGitSource'] = ['repositoryUrl' => $repositoryUrl];
        }

        $variants = [];
        $variantFiles = [];
        foreach ($mapping['variants'] ?? [] as $variant) {
            $price = self::field('price', self::value($record, $variant['price'] ?? null));
            if ($price === null) {
                continue;
            }
            $files = self::media($record, $variant['files'] ?? null);
            $fileUrls = array_values(array_filter($files, 'is_string'));
            $variants[] = array_filter([
                'name' => (string) $variant['name'],
                'price' => $price,
                'includes3dModel' => (bool) ($variant['includes_3d_model'] ?? false),
                'fileUrls' => $fileUrls ?: null,
                'updateMonths' => self::field('updateMonths', self::value($record, $variant['update_months'] ?? null)),
            ], fn ($value) => $value !== null);
            $variantFiles[] = array_values(array_filter($files, fn ($file) => $file instanceof FileSource));
        }
        if ($variants !== []) {
            $payload['variants'] = $variants;
        }

        $files = array_merge($imageFiles, ...$variantFiles);
        foreach ($files as $file) {
            if (! $file->exists()) {
                throw new ItemFailure(ItemFailure::FILE_NOT_FOUND, [ItemFailure::FILE_DETAIL => $file->source()]);
            }
        }
        if ($files !== [] && $categoryId === null) {
            throw new ItemFailure(ItemFailure::VALIDATION_ERROR, ['category' => self::REQUIRED]);
        }

        $fingerprint = hash(self::HASH, json_encode([
            $payload,
            array_map(self::fileFingerprint(...), $imageFiles),
            $second instanceof FileSource ? $second->source() : null,
            array_map(fn (array $group) => array_map(self::fileFingerprint(...), $group), $variantFiles),
        ], JSON_THROW_ON_ERROR));

        return new Item(
            $key,
            $sku,
            $categoryId,
            $payload,
            $imageFiles,
            $second instanceof FileSource ? $second : null,
            $variantFiles,
            $fingerprint,
        );
    }

    private static function media(ItemRecord $record, ?string $path): array
    {
        $value = self::value($record, $path);
        $entries = match (true) {
            $value instanceof Traversable => iterator_to_array($value, false),
            is_array($value) => array_values($value),
            default => [$value],
        };
        $media = [];
        foreach ($entries as $entry) {
            if ($entry !== null && $entry !== '') {
                $media[] = self::resolve($record, $entry);
            }
        }

        return $media;
    }

    private static function resolve(ItemRecord $record, mixed $entry): string|FileSource
    {
        if ($entry instanceof FileSource) {
            return $entry;
        }
        if (! $entry instanceof SplFileInfo) {
            $text = (string) $entry;
            foreach (self::URL_SCHEMES as $scheme) {
                if (str_starts_with($text, $scheme)) {
                    return $text;
                }
            }
        }

        return $record->file($entry);
    }

    private static function unique(array $media): array
    {
        $seen = [];
        $unique = [];
        foreach ($media as $entry) {
            $key = $entry instanceof FileSource ? $entry->source() : $entry;
            if (! isset($seen[$key])) {
                $seen[$key] = true;
                $unique[] = $entry;
            }
        }

        return $unique;
    }

    private static function fileFingerprint(FileSource $file): array
    {
        return [$file->source(), $file->size(), $file->modifiedAt()];
    }

    private static function value(ItemRecord $record, ?string $path): mixed
    {
        return $path === null ? null : $record->value($path);
    }

    private static function field(string $field, mixed $value): mixed
    {
        $value = $value instanceof BackedEnum ? $value->value : $value;
        if ($value === null || is_bool($value) || is_int($value)) {
            return $value;
        }
        if (is_float($value)) {
            return self::number($value);
        }
        $text = (string) $value;
        if (in_array($field, self::NUMERIC_FIELDS, true) && is_numeric($text)) {
            return self::number($text + 0);
        }

        return $text;
    }

    private static function number(int|float $value): int|float
    {
        return is_float($value) && $value == (int) $value ? (int) $value : $value;
    }

    private static function text(mixed $value): ?string
    {
        return match (true) {
            $value === null, $value === '' => null,
            is_bool($value) => $value ? self::YES : self::NO,
            $value instanceof BackedEnum => (string) $value->value,
            is_scalar($value), $value instanceof Stringable => (string) $value,
            default => null,
        };
    }

    private static function orientation(mixed $value): ?array
    {
        if (is_string($value)) {
            $value = json_decode($value, true);
        }
        if (! is_array($value)) {
            return null;
        }
        $orientation = [];
        foreach (self::ORIENTATION_AXES as $axis) {
            if (! is_numeric($value[$axis] ?? null)) {
                return null;
            }
            $orientation[$axis] = (int) $value[$axis];
        }

        return $orientation;
    }
}
