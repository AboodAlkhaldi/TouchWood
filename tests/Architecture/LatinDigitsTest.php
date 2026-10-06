<?php

declare(strict_types=1);

use Symfony\Component\Finder\Finder;

/*
| Latin digits everywhere (the owner, 2026-10-06; frontend.md §1.8): "no any arabic numbers across
| the whole system". Nothing the system writes carries an Arabic-Indic (U+0660) or Extended
| Arabic-Indic (U+06F0) digit - not the words in the language files, and not a formatter asked for
| Arabic numerals. Typed ones are accepted and turned (Shared\Domain\Text\LatinDigits, lib/digits.ts,
| the code boxes), which is why those files, and the tests that type such digits, are the only
| places they appear.
*/

/** The repository's root: these read files, and need no application booted. */
function latinDigitsRoot(): string
{
    return (string) realpath(dirname(__DIR__, 2));
}

const ARABIC_DIGIT = '/[\x{0660}-\x{0669}\x{06F0}-\x{06F9}]/u';

/**
 * @param  list<string>  $directories
 * @param  list<string>  $names
 * @return list<string>
 */
function latinDigitsFiles(array $directories, array $names): array
{
    $files = [];

    foreach ((new Finder)->files()->in(array_map(static fn (string $directory): string => latinDigitsRoot().'/'.$directory, $directories))->name($names) as $file) {
        $files[] = $file->getRealPath();
    }

    sort($files);

    return $files;
}

it('writes no Arabic-Indic digit in any language file', function () {
    $files = latinDigitsFiles(['src', 'lang'], ['*.php']);
    $languageFiles = array_values(array_filter($files, static fn (string $path): bool => preg_match('#[\\\\/]lang[\\\\/]#', $path) === 1));

    // Something must be read, or this test is about nothing.
    expect($languageFiles)->not->toBeEmpty();

    $offending = array_values(array_filter($languageFiles, static fn (string $path): bool => preg_match(ARABIC_DIGIT, (string) file_get_contents($path)) === 1));

    expect($offending)->toBe([]);
});

it('never asks a formatter for Arabic numerals in the screens', function () {
    $files = latinDigitsFiles(['resources/js'], ['*.ts', '*.tsx']);

    expect($files)->not->toBeEmpty();

    $offending = [];

    foreach ($files as $path) {
        $text = (string) file_get_contents($path);

        if (preg_match('/nu-arab|numerals=\{?[\'"]arab|numberingSystem:\s*[\'"]arab/', $text) === 1) {
            $offending[] = $path;
        }
    }

    expect($offending)->toBe([]);
});

it('writes Arabic-Indic digits in the screens only where typed ones are turned into Latin', function () {
    $files = latinDigitsFiles(['resources/js'], ['*.ts', '*.tsx']);
    $carrying = array_values(array_filter($files, static fn (string $path): bool => preg_match(ARABIC_DIGIT, (string) file_get_contents($path)) === 1));

    // The code boxes' pattern lets a typed digit through to be turned; digits.ts turns them.
    expect(array_map(static fn (string $path): string => str_replace('\\', '/', substr($path, strlen(latinDigitsRoot()) + 1)), $carrying))
        ->toBe(['resources/js/components/CodeInput.tsx', 'resources/js/lib/digits.ts']);
});
