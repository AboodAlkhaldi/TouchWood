<?php

declare(strict_types=1);

use Shared\Domain\Text\LatinDigits;

/*
| Latin digits everywhere (owner, 2026-10-06; frontend.md §1.8): what a person types on an Arabic,
| Persian or Urdu keyboard is the same number as 0-9, and is saved as 0-9.
*/

it('turns Arabic-Indic and Extended Arabic-Indic digits into Latin ones', function (string $typed, string $saved) {
    expect(LatinDigits::of($typed))->toBe($saved);
})->with([
    'Arabic-Indic' => ['٠١٢٣٤٥٦٧٨٩', '0123456789'],
    'Extended Arabic-Indic' => ['۰۱۲۳۴۵۶۷۸۹', '0123456789'],
    'mixed in a CR number' => ['س ت-١٠١٠١٢٣٤٥٦', 'س ت-1010123456'],
    'an address line' => ["طريق الملك فهد ٧\nالرياض ١٢٣٤٥", "طريق الملك فهد 7\nالرياض 12345"],
]);

it('leaves everything that is not a digit as it was', function (string $text) {
    expect(LatinDigits::of($text))->toBe($text);
})->with([
    'Latin already' => ['TW-CO-2026-0042'],
    'Arabic letters and punctuation' => ['شركة النور، الرياض'],
    // Not digits: kept, so a field that has its own rule for them still sees them.
    'the Arabic decimal and thousands marks' => ['٫ ٬'],
    'nothing' => [''],
]);
