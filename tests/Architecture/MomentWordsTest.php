<?php

declare(strict_types=1);

/*
| The words a typed moment can say (resources/js/lib/moments.ts; pricing.md §4.3.1 "Moments", Q4). The
| moment's check answers its sentence by key - `MOMENT_WORDS` - which no t() call names, so
| TranslationKeysTest cannot see them; each is read out of the file here and looked up in both
| languages.
*/

it('has every sentence a typed moment can say, in Arabic and in English', function () {
    $root = dirname(__DIR__, 2);
    $source = (string) file_get_contents($root.'/resources/js/lib/moments.ts');

    preg_match('/MOMENT_WORDS = \{(.*?)\}/s', $source, $block);
    preg_match_all("/'ui\\.check\\.([a-z_]+)'/", $block[1] ?? '', $keys);

    // Something was read: a date, a time, not past, after the start.
    expect($keys[1])->toBe(['date', 'time', 'not_past', 'after_start']);

    foreach (['ar', 'en'] as $locale) {
        $check = (array) ((require $root."/lang/{$locale}/ui.php")['check'] ?? []);

        foreach ($keys[1] as $key) {
            expect($check[$key] ?? null)->toBeString("ui.check.{$key} is missing in {$locale}")
                ->and((string) ($check[$key] ?? ''))->toContain(':field');
        }
    }
});
