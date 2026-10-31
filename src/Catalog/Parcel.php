<?php

namespace Opotopo\SellerApi\Catalog;

final class Parcel
{
    private const PRECISION = 6;

    /**
     * @return ?array{weightGrams: int, lengthCm: int, widthCm: int, heightCm: int}
     */
    public static function of(mixed $weightGrams, mixed $lengthCm, mixed $widthCm, mixed $heightCm): ?array
    {
        $parcel = [];
        foreach (['weightGrams' => $weightGrams, 'lengthCm' => $lengthCm, 'widthCm' => $widthCm, 'heightCm' => $heightCm] as $field => $size) {
            if (! is_numeric($size) || $size <= 0) {
                return null;
            }
            $parcel[$field] = (int) ceil(round((float) $size, self::PRECISION));
        }

        return $parcel;
    }
}
