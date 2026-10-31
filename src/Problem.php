<?php

namespace Opotopo\SellerApi;

use Throwable;

final class Problem
{
    private const SEPARATOR = '; ';

    public static function message(Throwable $failure): string
    {
        $problem = $failure instanceof ApiException ? $failure->problem() : [];
        $details = self::details(is_array($problem['details'] ?? null) ? $problem['details'] : []);

        return implode(' — ', array_filter([$problem['detail'] ?? $problem['title'] ?? $failure->getMessage(), $details]));
    }

    public static function details(array $details): string
    {
        return implode(self::SEPARATOR, array_map(
            fn ($key, $value) => $key.': '.(is_scalar($value) ? $value : json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)),
            array_keys($details),
            $details,
        ));
    }
}
