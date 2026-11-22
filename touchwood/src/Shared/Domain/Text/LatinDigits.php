<?php

declare(strict_types=1);

namespace Shared\Domain\Text;

/**
 * Every digit in Latin (0-9): the owner's rule for the whole system (2026-10-06, frontend.md §1.8) -
 * "no any arabic numbers across the whole system, so we dont struggle at matching anything in
 * future".
 *
 * An Arabic keyboard types ٠١٢٣٤٥٦٧٨٩ (Arabic-Indic, U+0660) and a Persian or Urdu one ۰۱۲۳۴۵۶۷۸۹
 * (Extended Arabic-Indic, U+06F0); both are accepted where a person types an identifier or an
 * address, and saved as 0-9, so the same number is one string however it was typed. Nothing else
 * in the text changes.
 */
final class LatinDigits
{
    /** @var array<string, string> */
    private const array DIGITS = [
        '٠' => '0', '١' => '1', '٢' => '2', '٣' => '3', '٤' => '4', '٥' => '5', '٦' => '6', '٧' => '7', '٨' => '8', '٩' => '9',
        '۰' => '0', '۱' => '1', '۲' => '2', '۳' => '3', '۴' => '4', '۵' => '5', '۶' => '6', '۷' => '7', '۸' => '8', '۹' => '9',
    ];

    public static function of(string $text): string
    {
        return strtr($text, self::DIGITS);
    }
}
