<?php

declare(strict_types=1);

/*
| Geist's writing rules for every English word on a screen (frontend.md 1.10): never "please",
| "successfully", "Unable to", "Something went wrong" or "Oops".
|
| It reads the language files rather than the screens: a screen has no words of its own, it asks
| for them through t() (frontend.md 1.5), so a banned phrase can only reach a person from here.
| Emails and SMS texts are not screens and are left out by name, so a new file is checked the moment
| it is written.
*/

/**
 * The files that hold emails and SMS texts, not screen words. Each is named on purpose: a file left
 * out here is a file nobody checks.
 */
const WRITING_RULES_NOT_SCREENS = [
    // SecurityMail's subjects and lines, and TemporarySecurityMessages' SMS codes.
    'src/Modules/Access/Presentation/lang/en/messages.php',
];

/**
 * The banned phrases a text holds, as written in it.
 *
 * @return list<string>
 */
function bannedScreenPhrasesIn(string $text): array
{
    $found = [];

    foreach (['please', 'successfully', 'unable to', 'something went wrong', 'oops'] as $phrase) {
        if (preg_match_all('/\b'.preg_quote($phrase, '/').'\b/i', $text, $matches) > 0) {
            array_push($found, ...$matches[0]);
        }
    }

    return $found;
}

/**
 * One language file's lines, flattened to "key.nested" => text.
 *
 * @param  array<array-key, mixed>  $lines
 * @return array<string, string>
 */
function writingRulesLines(array $lines, string $prefix = ''): array
{
    $flat = [];

    foreach ($lines as $key => $value) {
        $full = $prefix === '' ? (string) $key : $prefix.'.'.$key;

        if (is_array($value)) {
            $flat = [...$flat, ...writingRulesLines($value, $full)];

            continue;
        }

        $flat[$full] = (string) $value;
    }

    return $flat;
}

it('detects the banned phrases, whatever their case', function (string $text) {
    expect(bannedScreenPhrasesIn($text))->not->toBeEmpty();
})->with([
    'please' => ['Please try again later.'],
    'please, mid-sentence' => ['Your account is blocked — please contact us.'],
    'successfully' => ['Settings saved successfully'],
    'unable to' => ['Unable to verify the code.'],
    'something went wrong' => ['Something Went Wrong'],
    'oops' => ['Oops, that failed.'],
]);

it('ignores ordinary words', function () {
    expect(bannedScreenPhrasesIn("Couldn't verify the code. Try again."))->toBeEmpty()
        ->and(bannedScreenPhrasesIn('Settings saved'))->toBeEmpty()
        ->and(bannedScreenPhrasesIn('Failed to load the page.'))->toBeEmpty()
        // A word that only begins like a banned one.
        ->and(bannedScreenPhrasesIn('A pleasant surprise'))->toBeEmpty();
});

it('leaves out only files that exist', function () {
    $root = dirname(__DIR__, 2);

    foreach (WRITING_RULES_NOT_SCREENS as $file) {
        expect(is_file($root.'/'.$file))->toBeTrue("{$file} is listed as not a screen, and is not there");
    }
});

it('finds no banned phrase in any English word a screen shows', function () {
    $root = dirname(__DIR__, 2);
    $files = [
        ...(glob($root.'/lang/en/*.php') ?: []),
        ...(glob($root.'/src/Modules/*/Presentation/lang/en/*.php') ?: []),
    ];

    $violations = [];
    $scanned = 0;
    $lines = 0;

    foreach ($files as $file) {
        $where = str_replace('\\', '/', substr($file, strlen($root) + 1));

        if (in_array($where, WRITING_RULES_NOT_SCREENS, true)) {
            continue;
        }

        $scanned++;

        foreach (writingRulesLines((array) require $file) as $key => $text) {
            $lines++;
            $banned = bannedScreenPhrasesIn($text);

            if ($banned !== []) {
                $violations[] = "{$where}: {$key} says \"".implode('", "', $banned).'"';
            }
        }
    }

    // Guards against a moved directory making this pass over nothing.
    expect($scanned)->toBeGreaterThan(30)
        ->and($lines)->toBeGreaterThan(500)
        ->and($violations)->toBe([]);
});
