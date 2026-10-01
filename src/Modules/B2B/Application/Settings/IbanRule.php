<?php

declare(strict_types=1);

namespace Modules\B2B\Application\Settings;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * An International Bank Account Number whose check digits are right (ISO 13616; b2b.md amendment
 * 12(b)): two letters, two check digits from 02 to 98, then 11 to 30 letters and digits — 15 to 34 in
 * all — and the whole number, rearranged, leaves 1 when divided by 97. Spaces, as people write it in
 * groups of four, are ignored; the value is kept as it was typed. No country's length is hard-coded:
 * the store is the one that knows its own bank.
 */
final class IbanRule implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value) || ! self::valid($value)) {
            $fail('The IBAN is not a valid one: check its letters, its length and its check digits.');
        }
    }

    public static function valid(string $value): bool
    {
        $iban = strtoupper(str_replace(' ', '', $value));

        if (preg_match('/\A[A-Z]{2}[0-9]{2}[A-Z0-9]{11,30}\z/', $iban) !== 1) {
            return false;
        }

        // Check digits run from 02 to 98. 00, 01 and 99 leave the same remainder as 97, 98 and 02,
        // so the remainder alone would let them through (the review of step 5).
        if (in_array(substr($iban, 2, 2), ['00', '01', '99'], true)) {
            return false;
        }

        // The first four characters go to the end, and every letter becomes two digits (A = 10 …
        // Z = 35); the number then leaves 1 when divided by 97. It is far too long for an integer,
        // so the remainder is carried one digit at a time — no arbitrary-precision extension needed.
        $rearranged = substr($iban, 4).substr($iban, 0, 4);
        $digits = '';

        foreach (str_split($rearranged) as $character) {
            $digits .= ctype_alpha($character) ? (string) (ord($character) - ord('A') + 10) : $character;
        }

        $remainder = 0;

        foreach (str_split($digits) as $digit) {
            $remainder = ($remainder * 10 + (int) $digit) % 97;
        }

        return $remainder === 1;
    }
}
