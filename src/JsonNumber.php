<?php

namespace Opotopo\SellerApi;

final class JsonNumber
{
    public static function of(mixed $value): int|float|null
    {
        if (is_int($value)) {
            return $value;
        }
        if (! is_float($value) && ! is_numeric($value)) {
            return null;
        }
        $number = is_float($value) ? $value : $value + 0;

        return is_float($number) && $number == (int) $number ? (int) $number : $number;
    }
}
