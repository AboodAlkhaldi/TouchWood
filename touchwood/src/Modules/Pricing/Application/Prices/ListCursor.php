<?php

declare(strict_types=1);

namespace Modules\Pricing\Application\Prices;

/**
 * The cursor of Pricing's own keyset lists - sales and category discounts (pricing.md §4.3.1 "Show
 * More"): the last row's keys - the state's rank, a number from its dates, its id - as JSON in URL-safe
 * base64, opaque to the page. One that is not one starts at the top, as Catalog's.
 */
final class ListCursor
{
    /**
     * @param  array{int, string, string}|null  $keys
     */
    public static function encode(?array $keys): ?string
    {
        return $keys === null ? null : rtrim(strtr(base64_encode((string) json_encode($keys)), '+/', '-_'), '=');
    }

    /**
     * @return array{int, string, string}|null
     */
    public static function decode(?string $cursor): ?array
    {
        if ($cursor === null || $cursor === '' || strlen($cursor) > 512) {
            return null;
        }

        $json = base64_decode(strtr($cursor, '-_', '+/'), true);
        $keys = $json === false ? null : json_decode($json, true);

        if (! is_array($keys) || count($keys) !== 3 || ! is_int($keys[0] ?? null) || ! is_string($keys[1] ?? null) || ! is_string($keys[2] ?? null)) {
            return null;
        }

        // The number is the database's own (an epoch, perhaps negative); the id letters and digits.
        if (preg_match('/\A-?\d{1,20}(\.\d{1,9})?\z/', $keys[1]) !== 1 || preg_match('/\A[0-9a-z]{1,64}\z/', $keys[2]) !== 1) {
            return null;
        }

        return [$keys[0], $keys[1], $keys[2]];
    }
}
