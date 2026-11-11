<?php

declare(strict_types=1);

use Modules\Content\Domain\ValueObject\RichText;

/*
| One case table for the links staff type (content.md §1.1 rule 16), read here against RichText::link and by
| tests/Frontend/links.test.ts against the editors' own check (links.ts): what one refuses, the other refuses
| (PR 7a review: JavaScript's trim() took a no-break space the server keeps and refuses; `new URL()` took
| `https:///x` the server refused).
*/

it('keeps or refuses every link of the shared table as the editors do', function (string $typed, ?string $kept) {
    expect(RichText::link($typed))->toBe($kept);
})->with(function (): array {
    /** @var array{cases: list<array{typed: string, kept: string|null}>} $table */
    $table = json_decode((string) file_get_contents(__DIR__.'/link-cases.json'), true, flags: JSON_THROW_ON_ERROR);

    return array_map(static fn (array $case): array => [$case['typed'], $case['kept']], $table['cases']);
});
