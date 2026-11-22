<?php

declare(strict_types=1);

use Modules\Platform\Public\YouTubeLink;

/*
| flags-2026-11.md S4.4: staff paste any YouTube link - youtube.com/watch?v=, youtu.be/, /shorts/, /embed/ - and only the
| video's 11-character id is kept; anything else is refused. The cases are a table the page's own check reads too
| (tests/Frontend/youtube.test.ts), so what one refuses, the other refuses.
*/

/** @return array<string, array{string, string|null}> */
function youTubeLinkCases(): array
{
    /** @var array{cases: list<array{pasted: string, id: string|null}>} $table */
    $table = json_decode((string) file_get_contents(__DIR__.'/youtube-link-cases.json'), true, flags: JSON_THROW_ON_ERROR);
    $cases = [];

    foreach ($table['cases'] as $case) {
        $cases[json_encode($case['pasted'], JSON_THROW_ON_ERROR)] = [$case['pasted'], $case['id']];
    }

    return $cases;
}

it('reads the table of cases, both kinds in it', function () {
    $ids = array_column(youTubeLinkCases(), 1);

    expect(count($ids))->toBeGreaterThan(30)
        ->and(in_array(null, $ids, true))->toBeTrue()
        ->and(in_array('dQw4w9WgXcQ', $ids, true))->toBeTrue();
});

it('keeps each pasted link\'s video id, or refuses it, as the table says', function (string $pasted, ?string $id) {
    expect(YouTubeLink::id($pasted))->toBe($id);
})->with(youTubeLinkCases());

it('refuses a pasted text far longer than any link YouTube gives', function () {
    expect(YouTubeLink::id('https://youtu.be/dQw4w9WgXcQ?'.str_repeat('a', 2000)))->toBeNull()
        ->and(YouTubeLink::id('https://youtu.be/dQw4w9WgXcQ?'.str_repeat('a', 1900)))->toBe('dQw4w9WgXcQ');
});

it('knows an id written as YouTube writes one', function () {
    expect(YouTubeLink::isId('dQw4w9WgXcQ'))->toBeTrue()
        ->and(YouTubeLink::isId('dQw4w9WgXc'))->toBeFalse()
        ->and(YouTubeLink::isId('dQw4w9WgXc!'))->toBeFalse()
        ->and(YouTubeLink::isId("dQw4w9WgXcQ\n"))->toBeFalse();
});
