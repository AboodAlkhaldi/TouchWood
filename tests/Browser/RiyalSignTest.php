<?php

declare(strict_types=1);

use Database\Seeders\PlatformSeeder;

use function Pest\Laravel\seed;

/*
| The Saudi riyal sign, U+20C1, has a face of its own (resources/css/app.css, "TouchWood Riyal"): IBM Plex Sans Arabic has
| no glyph for it, so each device drew it with its own font or as a box (the owner's report, 2026-11-24). The face is
| fetched only for a page that shows the sign (`unicode-range`), in both weights, and every other character stays IBM
| Plex's.
*/

beforeEach(function () {
    seed(PlatformSeeder::class);
});

it('draws the riyal sign with its own face, in both weights, and nothing else with it', function () {
    $page = visit('/sa/ar');
    expect(browserSoon($page, "document.readyState === 'complete'", 90))->toBeTrue();

    $loaded = $page->script(<<<'JS'
        (async () => {
            const regular = await document.fonts.load('400 16px "TouchWood Riyal"', '⃁');
            const bold = await document.fonts.load('700 16px "TouchWood Riyal"', '⃁');
            const latin = await document.fonts.load('400 16px "TouchWood Riyal"', 'A');
            return [regular.length, bold.length, latin.length];
        })()
        JS);

    // One face each for the sign; none for a letter, which IBM Plex keeps.
    expect($loaded)->toBe([1, 1, 0]);

    // The page's own font names the riyal's face first, so a price picks it up wherever it is written.
    expect($page->script("getComputedStyle(document.body).fontFamily.startsWith('\"TouchWood Riyal\"')"))->toBeTrue();
    $page->assertNoJavaScriptErrors();
});
