<?php

declare(strict_types=1);

use Modules\Content\Domain\Exception\InvalidContentInput;
use Modules\Content\Domain\ValueObject\RichText;

/*
| The text format of pages, posts and projects (content.md §1.2): Catalog's blocks, extended with a second
| heading level, numbered lists, links, images and tables; no HTML stored; 100,000 characters and 50 images
| a document.
*/

/**
 * The rule a document breaks, or null when it is kept.
 */
function richTextRefusal(mixed $document): ?string
{
    try {
        RichText::of('body', $document);
    } catch (InvalidContentInput $refused) {
        return $refused->rule;
    }

    return null;
}

/**
 * @param  list<mixed>  $blocks
 * @return array{blocks: list<mixed>}
 */
function richTextOf(array $blocks): array
{
    return ['blocks' => $blocks];
}

it('keeps every kind of block as it came', function () {
    $document = richTextOf([
        ['type' => 'heading', 'level' => 2, 'runs' => [['text' => 'Installation']]],
        ['type' => 'heading', 'level' => 3, 'runs' => [['text' => 'Tools']]],
        ['type' => 'paragraph', 'runs' => [['text' => 'See '], ['text' => 'our guide', 'link' => '/pages/guide'], ['text' => ' and '], ['text' => 'more', 'bold' => true, 'link' => 'https://example.com/a?b=c']]],
        ['type' => 'list', 'items' => [[['text' => 'Soft close']], [['text' => 'Tool-free', 'bold' => true]]]],
        ['type' => 'list', 'ordered' => true, 'items' => [[['text' => 'Measure']], [['text' => 'Fit']]]],
        ['type' => 'image', 'ref' => 3, 'alt' => 'The cabinet open'],
        ['type' => 'table', 'head' => [[['text' => 'Size']], [['text' => 'Price']]], 'rows' => [[[['text' => '60']], []]]],
    ]);

    $text = RichText::of('body', $document);

    expect($text->toArray())->toBe($document)
        ->and($text->imageRefs())->toBe([3])
        ->and(RichText::fromJson('body', $text->toJson())->equals($text))->toBeTrue()
        ->and($text->isEmpty())->toBeFalse();
});

it('reads a block whose keys came back in another order, as jsonb gives them', function () {
    $document = ['blocks' => [['runs' => [['text' => 'x']], 'level' => 2, 'type' => 'heading']]];

    expect(RichText::of('body', $document)->toArray()['blocks'][0])->toBe(['type' => 'heading', 'level' => 2, 'runs' => [['text' => 'x']]]);
});

it('takes an empty document, a draft\'s English, and leaves whether it may be empty to the use case', function () {
    expect(RichText::of('body', ['blocks' => []])->isEmpty())->toBeTrue()
        ->and(RichText::empty()->toJson())->toBe('{"blocks":[]}');
});

it('saves every digit 0-9, in runs and descriptions', function () {
    $text = RichText::of('body', richTextOf([
        ['type' => 'paragraph', 'runs' => [['text' => 'المقاس ١٢٠ سم ۳']]],
        ['type' => 'image', 'ref' => 1, 'alt' => 'صورة ٢'],
    ]));

    expect($text->toArray()['blocks'][0]['runs'][0]['text'])->toBe('المقاس 120 سم 3')
        ->and($text->toArray()['blocks'][1]['alt'])->toBe('صورة 2');
});

it('joins runs side by side with the same marks, so one text has one shape', function () {
    $text = RichText::of('body', richTextOf([['type' => 'paragraph', 'runs' => [
        ['text' => 'a'], ['text' => 'b'], ['text' => 'c', 'bold' => true], ['text' => 'd', 'bold' => true],
        ['text' => 'e', 'link' => '/x'], ['text' => 'f', 'link' => '/y'],
    ]]]));

    expect($text->toArray()['blocks'][0]['runs'])->toBe([
        ['text' => 'ab'], ['text' => 'cd', 'bold' => true], ['text' => 'e', 'link' => '/x'], ['text' => 'f', 'link' => '/y'],
    ]);
});

it('keeps a page of the shop with its leading slash, and a full https address', function () {
    expect(RichText::link('categories/chairs'))->toBe('/categories/chairs')
        ->and(RichText::link('/pages/about'))->toBe('/pages/about')
        ->and(RichText::link('/'))->toBe('/')
        ->and(RichText::link('https://example.com/x'))->toBe('https://example.com/x')
        ->and(RichText::link('HTTPS://example.com'))->toBe('HTTPS://example.com');
});

it('refuses a link that could leave the rules', function (string $address) {
    expect(RichText::link($address))->toBeNull()
        ->and(richTextRefusal(richTextOf([['type' => 'paragraph', 'runs' => [['text' => 'x', 'link' => $address]]]])))->toBe(InvalidContentInput::LINK);
})->with([
    'a script' => ['javascript:alert(1)'],
    'another site, no scheme' => ['//evil.example'],
    'a backslash' => ['/pages\\evil'],
    'plain http' => ['http://example.com'],
    'another scheme' => ['mailto:a@b.c'],
    'a space inside' => ['/pages/a b'],
    'no host' => ['https://'],
    'empty' => [''],
    'past the length' => ['/'.str_repeat('a', RichText::MAX_LINK)],
]);

it('refuses a document of no blocks list, or a block of no known kind', function (mixed $document) {
    expect(richTextRefusal($document))->toBe(InvalidContentInput::FORMAT);
})->with([
    'not an array' => ['text'],
    'other keys' => [['blocks' => [], 'html' => '<b>x</b>']],
    'blocks a map' => [['blocks' => ['a' => []]]],
    'an html block' => [richTextOf([['type' => 'html', 'html' => '<script>x</script>']])],
    'a block not an object' => [richTextOf(['x'])],
    'a paragraph with extra keys' => [richTextOf([['type' => 'paragraph', 'runs' => [['text' => 'x']], 'style' => 'red']])],
    'a run with extra keys' => [richTextOf([['type' => 'paragraph', 'runs' => [['text' => 'x', 'color' => 'red']]]])],
    'a run with no text' => [richTextOf([['type' => 'paragraph', 'runs' => [['bold' => true]]]])],
    'bold written false' => [richTextOf([['type' => 'paragraph', 'runs' => [['text' => 'x', 'bold' => false]]]])],
    'a paragraph of no runs' => [richTextOf([['type' => 'paragraph', 'runs' => []]])],
    'an empty run' => [richTextOf([['type' => 'paragraph', 'runs' => [['text' => '']]]])],
    'text not UTF-8' => [richTextOf([['type' => 'paragraph', 'runs' => [['text' => "\xC3\x28"]]]])],
    'ordered written false' => [richTextOf([['type' => 'list', 'ordered' => false, 'items' => [[['text' => 'x']]]]])],
    'a list of no items' => [richTextOf([['type' => 'list', 'items' => []]])],
    'a heading without its level' => [richTextOf([['type' => 'heading', 'runs' => [['text' => 'x']]]])],
    'a table of no rows list' => [richTextOf([['type' => 'table', 'head' => [[], []], 'rows' => 'x']])],
]);

it('keeps a run of real text on one line', function (string $text) {
    expect(richTextRefusal(richTextOf([['type' => 'paragraph', 'runs' => [['text' => $text]]]])))->toBe(InvalidContentInput::ONE_LINE);
})->with([["a\nb"], ["a\tb"], ["a\u{2028}b"], ["a\x00b"]]);

it('keeps a heading at level 2 or 3', function (int $level) {
    expect(richTextRefusal(richTextOf([['type' => 'heading', 'level' => $level, 'runs' => [['text' => 'x']]]])))->toBe(InvalidContentInput::HEADING_LEVEL);
})->with([1, 4, 0]);

it('refuses an image with no description or a ref that is no row', function (mixed $ref, mixed $alt) {
    expect(richTextRefusal(richTextOf([['type' => 'image', 'ref' => $ref, 'alt' => $alt]])))->toBe(InvalidContentInput::IMAGE);
})->with([
    'no description' => [1, ''],
    'spaces for a description' => [1, '   '],
    'a description not text' => [1, 5],
    'ref zero' => [0, 'x'],
    'ref text' => ['1', 'x'],
    'ref too large' => [1_000_000, 'x'],
]);

it('counts each image once in the refs, and holds 50 a document', function () {
    $images = array_map(static fn (int $ref): array => ['type' => 'image', 'ref' => $ref % 3 + 1, 'alt' => 'x'], range(1, RichText::MAX_IMAGES));

    expect(RichText::of('body', richTextOf($images))->imageRefs())->toBe([2, 3, 1])
        ->and(richTextRefusal(richTextOf([...$images, ['type' => 'image', 'ref' => 1, 'alt' => 'x']])))->toBe(InvalidContentInput::TOO_MANY_IMAGES);
});

it('holds a table of 2 to 8 columns and 1 to 100 rows, every row as wide as its heading', function () {
    $cells = static fn (int $count): array => array_fill(0, $count, [['text' => 'c']]);
    $table = static fn (int $columns, int $rows, ?int $last = null): array => richTextOf([[
        'type' => 'table',
        'head' => $cells($columns),
        'rows' => array_map(static fn (int $row): array => $cells($row === $rows && $last !== null ? $last : $columns), range(1, max(1, $rows))),
    ]]);

    expect(richTextRefusal($table(2, 1)))->toBeNull()
        ->and(richTextRefusal($table(8, 100)))->toBeNull()
        ->and(richTextRefusal($table(1, 1)))->toBe(InvalidContentInput::TABLE_COLUMNS)
        ->and(richTextRefusal($table(9, 1)))->toBe(InvalidContentInput::TABLE_COLUMNS)
        ->and(richTextRefusal($table(3, 101)))->toBe(InvalidContentInput::TABLE_ROWS)
        ->and(richTextRefusal(richTextOf([['type' => 'table', 'head' => $cells(3), 'rows' => []]])))->toBe(InvalidContentInput::TABLE_ROWS)
        ->and(richTextRefusal($table(3, 2, last: 4)))->toBe(InvalidContentInput::TABLE_ROW_COLUMNS);
});

it('holds 100,000 characters a document, words and descriptions together', function () {
    $half = str_repeat('a', RichText::MAX_CHARACTERS / 2);
    $at = richTextOf([['type' => 'paragraph', 'runs' => [['text' => $half]]], ['type' => 'paragraph', 'runs' => [['text' => $half]]]]);

    expect(RichText::of('body', $at)->characters())->toBe(RichText::MAX_CHARACTERS)
        ->and(richTextRefusal([...$at, 'blocks' => [...$at['blocks'], ['type' => 'image', 'ref' => 1, 'alt' => 'x']]]))->toBe(InvalidContentInput::TOO_LONG)
        // Counted in characters, not bytes: Arabic takes two bytes a letter.
        ->and(RichText::of('body', richTextOf([['type' => 'paragraph', 'runs' => [['text' => str_repeat('ب', RichText::MAX_CHARACTERS)]]]]))->characters())->toBe(RichText::MAX_CHARACTERS);
});

it('holds a ceiling of blocks and of a list\'s items', function () {
    $blocks = array_fill(0, RichText::MAX_BLOCKS + 1, ['type' => 'table', 'head' => [[], []], 'rows' => [[[], []]]]);
    $items = array_fill(0, RichText::MAX_ITEMS + 1, [['text' => 'x']]);

    expect(richTextRefusal(richTextOf($blocks)))->toBe(InvalidContentInput::TOO_MANY_BLOCKS)
        ->and(richTextRefusal(richTextOf(array_slice($blocks, 1))))->toBeNull()
        ->and(richTextRefusal(richTextOf([['type' => 'list', 'items' => $items]])))->toBe(InvalidContentInput::TOO_MANY_ITEMS)
        ->and(richTextRefusal(richTextOf([['type' => 'list', 'items' => array_slice($items, 1)]])))->toBeNull();
});

it('names the block a refusal is in, for the typed text to name its line', function () {
    try {
        RichText::of('body', richTextOf([['type' => 'paragraph', 'runs' => [['text' => 'fine']]], ['type' => 'heading', 'level' => 5, 'runs' => [['text' => 'x']]]]));
    } catch (InvalidContentInput $refused) {
        expect($refused->block)->toBe(1)
            ->and($refused->typedLine)->toBeNull();

        return;
    }

    throw new LogicException('Not refused.');
});

it('reads back a column holding what it would refuse, loudly', function () {
    expect(fn () => RichText::fromJson('body', '{"blocks": [{"type": "html"}]}'))->toThrow(InvalidContentInput::class)
        ->and(fn () => RichText::fromJson('body', 'not json'))->toThrow(InvalidContentInput::class);
});

it('keeps text that looks like HTML as the text it is', function () {
    $text = RichText::of('body', richTextOf([['type' => 'paragraph', 'runs' => [['text' => '<script>alert(1)</script>']]]]));

    expect($text->toArray()['blocks'][0]['runs'][0]['text'])->toBe('<script>alert(1)</script>');
});
