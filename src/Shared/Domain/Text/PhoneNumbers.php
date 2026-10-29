<?php

declare(strict_types=1);

namespace Shared\Domain\Text;

/**
 * The one rule for a phone number, any module's (owner, 2026-10-29, stage 7 step 3 Q2): E.164
 * (+9665…), any country (owner's decision, 2026-09-18). Spaces, dashes, dots and brackets are
 * dropped, a leading 00 becomes +, and digits typed on an Arabic keyboard are the same digits
 * (access.md amendment 63). Each module refuses in its own error.
 */
final class PhoneNumbers
{
    private const string E164 = '/\A\+[1-9]\d{6,14}\z/';

    /**
     * @return string|null the number in E.164, or null when it is not one
     */
    public static function normalize(string $value): ?string
    {
        $digits = (string) preg_replace('/[\s\-.()]/', '', LatinDigits::of(trim($value)));

        if (str_starts_with($digits, '00')) {
            $digits = '+'.substr($digits, 2);
        }

        return preg_match(self::E164, $digits) === 1 ? $digits : null;
    }
}
