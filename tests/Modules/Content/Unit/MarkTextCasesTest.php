<?php

declare(strict_types=1);

use Modules\Content\Application\Text\MarkText;

/*
| One case table for bodies as staff type them (content.md §1.2), read here against MarkText::blocks and by
| tests/Frontend/marks-content.test.ts against the editor's preview (components/content/marks.ts): the preview draws
| the blocks the server keeps.
*/

it('reads every body of the shared table into the blocks the editor previews', function (string $typed, array $blocks) {
    expect(MarkText::blocks('body', $typed)->toArray()['blocks'])->toBe($blocks);
})->with(function (): array {
    /** @var array{cases: list<array{typed: string, blocks: list<array<string, mixed>>}>} $table */
    $table = json_decode((string) file_get_contents(__DIR__.'/mark-cases.json'), true, flags: JSON_THROW_ON_ERROR);

    return array_map(static fn (array $case): array => [$case['typed'], $case['blocks']], $table['cases']);
});
