<?php

namespace Opotopo\SellerApi\Catalog;

use BackedEnum;
use InvalidArgumentException;
use Opotopo\SellerApi\Files\FileSource;
use Opotopo\SellerApi\JsonNumber;
use SplFileInfo;
use Stringable;
use Traversable;

final class ItemBuilder
{
    private const URL_SCHEMES = ['http://', 'https://'];

    private const NUMERIC_FIELDS = ['price', 'quantity', 'updateMonths'];

    private const ORIENTATION_AXES = ['rollDeg', 'pitchDeg', 'yawDeg'];

    private const PARCEL_SIZES = ['weight', 'length', 'width', 'height'];

    private const YES = 'да';

    private const NO = 'нет';

    private const HASH = 'sha256';

    private const EACH = 'each';

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
        $shopCategoryPath = self::levels(self::value($record, $mapping['shop_category'] ?? null));
        if ($shopCategoryPath !== []) {
            $payload['shopCategoryPath'] = $shopCategoryPath;
        }

        $attributes = [];
        foreach (self::attributes($record, $mapping['attributes'] ?? []) as $name => $value) {
            $value = self::text($value);
            if ($value !== null) {
                $attributes[(string) $name] = $value;
            }
        }
        if ($attributes !== []) {
            $payload['attributes'] = $attributes;
        }

        $variants = [];
        $variantFiles = [];
        $variantImages = [];
        foreach (self::variants($record, $mapping['variants'] ?? []) as [$source, $variant, $name, $includes3dModel]) {
            $price = self::field('price', self::value($source, $variant['price'] ?? null));
            if ($price === null || $name === null) {
                continue;
            }
            $files = self::media($source, $variant['files'] ?? null);
            $fileUrls = array_values(array_filter($files, 'is_string'));
            $image = self::media($source, $variant['image'] ?? null)[0] ?? null;
            $variants[] = array_filter([
                'name' => $name,
                'price' => $price,
                'includes3dModel' => $includes3dModel,
                'fileUrls' => $fileUrls ?: null,
                'updateMonths' => self::field('updateMonths', self::value($source, $variant['update_months'] ?? null)),
                'imageUrl' => is_string($image) ? $image : null,
            ], fn ($value) => $value !== null);
            $variantFiles[] = array_values(array_filter($files, fn ($file) => $file instanceof FileSource));
            $variantImages[] = $image instanceof FileSource ? $image : null;
        }

        $roles = $mapping['images'] ?? [];
        $cover = array_slice(self::media($record, $roles['cover'] ?? null), 0, 1);
        $second = self::media($record, $roles['second'] ?? null)[0] ?? null;
        $images = self::unique(array_merge(
            $cover,
            self::media($record, $roles['gallery'] ?? null),
            $second === null ? [] : [$second],
            array_values(array_filter(array_column($variants, 'imageUrl'))),
            array_values(array_filter($variantImages)),
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

        $parcel = self::parcel($record, $mapping['parcel'] ?? []);
        if ($parcel !== null) {
            $payload['parcel'] = $parcel;
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
        if ($variants !== []) {
            $payload['variants'] = $variants;
        }

        $files = array_merge($imageFiles, ...$variantFiles);
        foreach ($files as $file) {
            if (! $file->exists()) {
                throw new ItemFailure(ItemFailure::FILE_NOT_FOUND, [ItemFailure::FILE_DETAIL => $file->source()]);
            }
        }
        $inputs = [
            $payload,
            array_map(self::fileFingerprint(...), $imageFiles),
            $second instanceof FileSource ? $second->source() : null,
            array_map(fn (array $group) => array_map(self::fileFingerprint(...), $group), $variantFiles),
        ];
        if (array_filter($variantImages) !== []) {
            $inputs[] = array_map(fn (?FileSource $image) => $image?->source(), $variantImages);
        }
        $fingerprint = hash(self::HASH, json_encode($inputs, JSON_THROW_ON_ERROR));

        return new Item(
            $key,
            $sku,
            $payload,
            $imageFiles,
            $second instanceof FileSource ? $second : null,
            $variantFiles,
            $variantImages,
            $fingerprint,
        );
    }

    private static function attributes(ItemRecord $record, array|string $mapping): iterable
    {
        if (is_string($mapping)) {
            $values = $record->value($mapping);

            return is_iterable($values) ? $values : [];
        }
        $values = [];
        foreach ($mapping as $name => $path) {
            $values[$name] = $record->value($path);
        }

        return $values;
    }

    /**
     * @return iterable<array{ItemRecord, array, ?string, bool}>
     */
    private static function variants(ItemRecord $record, array $mapping): iterable
    {
        if (! isset($mapping[self::EACH])) {
            foreach ($mapping as $variant) {
                yield [$record, $variant, (string) $variant['name'], (bool) ($variant['includes_3d_model'] ?? false)];
            }

            return;
        }
        foreach (self::entries($record->value($mapping[self::EACH])) as $element) {
            if (! $element instanceof ItemRecord) {
                throw new InvalidArgumentException('элемент перечня вариантов '.$mapping[self::EACH].' — не '.ItemRecord::class);
            }
            yield [
                $element,
                $mapping,
                self::text(self::value($element, $mapping['name'] ?? null)),
                (bool) self::value($element, $mapping['includes_3d_model'] ?? null),
            ];
        }
    }

    private static function entries(mixed $value): array
    {
        return match (true) {
            $value === null => [],
            $value instanceof Traversable => iterator_to_array($value, false),
            is_array($value) => array_values($value),
            default => [$value],
        };
    }

    private static function media(ItemRecord $record, ?string $path): array
    {
        $entries = self::entries(self::value($record, $path));
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

    private static function levels(mixed $value): array
    {
        return array_values(array_filter(array_map(self::text(...), self::entries($value)), fn (?string $level) => $level !== null));
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
            return JsonNumber::of($value);
        }
        $text = (string) $value;

        return in_array($field, self::NUMERIC_FIELDS, true) ? JsonNumber::of($text) ?? $text : $text;
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

    private static function parcel(ItemRecord $record, array $mapping): ?array
    {
        if ($mapping === []) {
            return null;
        }
        $parcel = Parcel::of(...array_map(fn (string $key) => self::value($record, $mapping[$key] ?? null), self::PARCEL_SIZES));
        $packaging = self::text(self::value($record, $mapping['packaging'] ?? null));

        return $parcel === null || $packaging === null ? $parcel : $parcel + ['packaging' => $packaging];
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
