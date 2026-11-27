<?php

namespace Opotopo\SellerApi\Orders;

use Opotopo\SellerApi\Catalog\Parcel;
use Opotopo\SellerApi\JsonNumber;

final class DispatchForm
{
    public const OWN_DELIVERY = 'COURIER';

    public const PICKUP = 'SELLER_PICKUP';

    public const CARRIER = ['CARRIER_DELIVERY', 'CARRIER_POINT'];

    public const DOCUMENTS = ['LABEL', 'WAYBILL'];

    public const TRACKING = ['carrierName', 'trackingNumber', 'trackingUrl'];

    public const DROP_OFF_POINT = 'dropOffPointId';

    public const SIZES = ['weight', 'length', 'width', 'height'];

    public const LINES = 'lines';

    /**
     * @param  array<string, mixed>  $fields
     * @param  list<array<string, mixed>>  $places
     * @param  callable(float): float  $grams
     * @param  callable(float): float  $centimeters
     *
     * @throws InvalidPlace
     * @throws MissingTrackingNumber
     */
    public static function request(array $fulfillment, array $fields, array $places, callable $grams, callable $centimeters): array
    {
        $method = $fulfillment['method'];

        return match (true) {
            $method === self::OWN_DELIVERY => self::tracking($fulfillment, $fields),
            in_array($method, self::CARRIER, true) => array_filter(
                self::texts($fields, [self::DROP_OFF_POINT]) + ['places' => self::places($fulfillment, $places, $grams, $centimeters)],
            ),
            default => [],
        };
    }

    /**
     * @return array<string, string>
     */
    private static function tracking(array $fulfillment, array $fields): array
    {
        $tracking = self::texts($fields, self::TRACKING);
        if ($fulfillment['trackingRequired'] && ! isset($tracking['trackingNumber'])) {
            throw new MissingTrackingNumber;
        }

        return $tracking;
    }

    /**
     * @param  list<string>  $names
     * @return array<string, string>
     */
    private static function texts(array $fields, array $names): array
    {
        $texts = [];
        foreach ($names as $name) {
            $value = trim((string) ($fields[$name] ?? ''));
            if ($value !== '') {
                $texts[$name] = $value;
            }
        }

        return $texts;
    }

    private static function places(array $fulfillment, array $places, callable $grams, callable $centimeters): array
    {
        $lines = array_flip(array_column($fulfillment['items'], 'id'));
        $requested = [];
        foreach (array_values($places) as $index => $place) {
            $quantities = array_filter(
                array_map(JsonNumber::of(...), array_intersect_key((array) ($place[self::LINES] ?? []), $lines)),
                static fn (int|float|null $quantity) => $quantity > 0,
            );
            $sizes = array_map(static fn (string $size) => trim((string) ($place[$size] ?? '')), self::SIZES);
            if ($quantities === [] && implode('', $sizes) === '') {
                continue;
            }
            [$weight, $length, $width, $height] = array_map(static fn (string $size) => is_numeric($size) ? (float) $size : 0.0, $sizes);
            $parcel = Parcel::of($grams($weight), $centimeters($length), $centimeters($width), $centimeters($height));
            if ($parcel === null) {
                throw new InvalidPlace($index + 1);
            }
            $requested[] = [
                'parcel' => $parcel,
                'lines' => array_map(
                    static fn (string|int $lineId, int|float $quantity) => ['lineId' => (string) $lineId, 'quantity' => $quantity],
                    array_keys($quantities),
                    $quantities,
                ),
            ];
        }

        return $requested;
    }
}
