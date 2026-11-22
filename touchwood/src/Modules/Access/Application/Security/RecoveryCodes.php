<?php

declare(strict_types=1);

namespace Modules\Access\Application\Security;

/**
 * The ten one-time codes that sign in in place of an app code (amendment 91): ten characters in two
 * groups of five, from letters and digits a person cannot mistake for one another. Typed in any case,
 * with or without the dash or spaces. Stored only as keyed hashes (Codes).
 */
final class RecoveryCodes
{
    public const int COUNT = 10;

    /** No 0/O, 1/I/L, 5/S or 8/B: a code read off paper must not be typed as another. */
    private const string ALPHABET = 'acdefghjkmnpqrtuvwxyz2345679';

    /**
     * @return list<string> "abcde-fghjk", ten of them, all different
     */
    public static function generate(): array
    {
        $codes = [];

        while (count($codes) < self::COUNT) {
            $code = '';

            for ($i = 0; $i < 10; $i++) {
                $code .= self::ALPHABET[random_int(0, strlen(self::ALPHABET) - 1)];
            }

            $codes[substr($code, 0, 5).'-'.substr($code, 5)] = true;
        }

        return array_keys($codes);
    }

    /**
     * The code as it is hashed: lower case, without the dash or spaces.
     */
    public static function normalize(string $typed): string
    {
        return strtolower((string) preg_replace('/[\s\-]+/u', '', $typed));
    }
}
