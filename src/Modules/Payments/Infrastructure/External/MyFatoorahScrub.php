<?php

declare(strict_types=1);

namespace Modules\Payments\Infrastructure\External;

/**
 * What of MyFatoorah's words may be kept (payments.md §1.1 rules 10, 13): nothing of the card but its brand,
 * nothing of the customer, and not the session's encryption key. Applied to everything before it is stored.
 */
final class MyFatoorahScrub
{
    /** Compared without regard to case: the docs write them both ways. */
    private const array DROPPED = ['encryptionkey', 'customer', 'cards', 'pan', 'panhash', 'token', 'cvv', 'ip'];

    /**
     * @param  array<array-key, mixed>  $data
     * @return array<array-key, mixed>
     */
    public static function clean(array $data): array
    {
        $kept = [];

        foreach ($data as $name => $value) {
            $key = strtolower((string) $name);

            if (in_array($key, self::DROPPED, true)) {
                continue;
            }

            if ($key === 'card') {
                $kept[$name] = is_array($value) ? array_intersect_key($value, ['Brand' => true]) : null;

                continue;
            }

            // The database refuses a NUL: the call would go unlogged.
            $kept[$name] = is_array($value) ? self::clean($value) : (is_string($value) ? str_replace("\0", '', $value) : $value);
        }

        return $kept;
    }
}
