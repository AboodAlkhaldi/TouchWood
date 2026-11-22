<?php

declare(strict_types=1);

namespace Modules\Sync\Application\Report;

use Modules\Sync\Domain\Exception\InvalidSyncInput;

/**
 * **Where Show More goes on** (pricing.md §4.3.1, Q6): the last row shown's key - a moment and an id - as
 * one opaque word for the address, read back as it was written. A cursor that is not one of ours is
 * refused (`after`), never taken as the first page.
 */
final class Cursor
{
    public static function of(string $moment, string|int $id): string
    {
        return rtrim(strtr(base64_encode($moment.'|'.$id), '+/', '-_'), '=');
    }

    /**
     * @param  'entry'|'read'  $of  an entry's id is a number, a read's a ULID
     * @return array{string, string}|null the moment and the id; null for none
     *
     * @throws InvalidSyncInput
     */
    public static function read(?string $cursor, string $of): ?array
    {
        if ($cursor === null || trim($cursor) === '') {
            return null;
        }

        $decoded = base64_decode(strtr(trim($cursor), '-_', '+/'), true);
        $parts = is_string($decoded) ? explode('|', $decoded) : [];
        $id = $of === 'entry' ? '/\A[0-9]{1,18}\z/' : '/\A[0-9a-hjkmnp-tv-z]{26}\z/';

        if (count($parts) !== 2 || preg_match('/\A\d{4}-\d{2}-\d{2}[ T][0-9:.]+([+-]\d{2}(:?\d{2})?|Z)?\z/', $parts[0]) !== 1 || preg_match($id, $parts[1]) !== 1 || strtotime($parts[0]) === false) {
            throw new InvalidSyncInput('after');
        }

        return [$parts[0], $parts[1]];
    }
}
