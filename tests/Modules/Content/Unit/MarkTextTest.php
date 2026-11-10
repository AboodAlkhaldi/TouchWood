<?php

declare(strict_types=1);

use Modules\Content\Application\Text\MarkText;
use Modules\Content\Domain\Exception\InvalidContentInput;
use Modules\Content\Domain\ValueObject\RichText;

/*
| The editor's typed marks and the stored blocks, each into the other (content.md §1.2), as Catalog's
| DescriptionText: a text saved unchanged stays as it was (lesson 160's shape).
*/

/**
 * @return list<array<string, mixed>>
 */
function markTextBlocks(string $typed): array
{
    return MarkText::blocks('body', $typed)->toArray()['blocks'];
}

/**
 * The refusal typed text gets: its rule and line.
 *
 * @return array{0: string, 1: int|null}
 */
function markTextRefusal(string $typed): array
{
    try {
        MarkText::blocks('body', $typed);
    } catch (InvalidContentInput $refused) {
        return [$refused->rule, $refused->typedLine];
    }

    throw new LogicException('Not refused.');
}

it('reads each mark as its block', function () {
    expect(markTextBlocks("# Installation\n\n## Tools\n\nFits **every** cabinet,\nsee [the guide](/pages/guide).\n\n- Soft close\n- **Tool-free**\n\n1. Measure\n2. Fit\n\n![The cabinet open](image:3)\n\n| Size | Price |\n| 60 |  |"))->toBe([
        ['type' => 'heading', 'level' => 2, 'runs' => [['text' => 'Installation']]],
        ['type' => 'heading', 'level' => 3, 'runs' => [['text' => 'Tools']]],
        ['type' => 'paragraph', 'runs' => [['text' => 'Fits '], ['text' => 'every', 'bold' => true], ['text' => ' cabinet, see '], ['text' => 'the guide', 'link' => '/pages/guide'], ['text' => '.']]],
        ['type' => 'list', 'items' => [[['text' => 'Soft close']], [['text' => 'Tool-free', 'bold' => true]]]],
        ['type' => 'list', 'ordered' => true, 'items' => [[['text' => 'Measure']], [['text' => 'Fit']]]],
        ['type' => 'image', 'ref' => 3, 'alt' => 'The cabinet open'],
        ['type' => 'table', 'head' => [[['text' => 'Size']], [['text' => 'Price']]], 'rows' => [[[['text' => '60']], []]]],
    ]);
});

it('reads a bold link, and a link to a full https address', function () {
    expect(markTextBlocks('**[Shop now](https://example.com/sale)** today'))->toBe([
        ['type' => 'paragraph', 'runs' => [['text' => 'Shop now', 'bold' => true, 'link' => 'https://example.com/sale'], ['text' => ' today']]],
    ]);
});

it('reads a mark written wrong as the text it is', function (string $typed, string $text) {
    expect(markTextBlocks($typed))->toBe([['type' => 'paragraph', 'runs' => [['text' => $text]]]]);
})->with([
    'bold left open' => ['a **b', 'a **b'],
    'brackets with no address' => ['[words] here', '[words] here'],
    'a heading with no space' => ['#Title', '#Title'],
    'a third heading level' => ['### Title', '### Title'],
    'a list with no space' => ['-item', '-item'],
    'a bar at one end only' => ['| a b', '| a b'],
    'html' => ['<b>bold</b> <script>x</script>', '<b>bold</b> <script>x</script>'],
]);

it('reads Arabic-Indic digits typed as 0-9, the numbered list\'s too', function () {
    expect(markTextBlocks("١. المقاس ١٢٠\n٢. الثاني"))->toBe([
        ['type' => 'list', 'ordered' => true, 'items' => [[['text' => 'المقاس 120']], [['text' => 'الثاني']]]],
    ]);
});

it('starts a new list when bullets turn to numbers', function () {
    expect(markTextBlocks("- a\n1. b"))->toBe([
        ['type' => 'list', 'items' => [[['text' => 'a']]]],
        ['type' => 'list', 'ordered' => true, 'items' => [[['text' => 'b']]]],
    ]);
});

it('refuses a link the rules refuse, naming its line', function (string $address) {
    expect(markTextRefusal("Fine\n\nSee [this]({$address})"))->toBe([InvalidContentInput::LINK, 3]);
})->with(['javascript:alert(1)', '//evil.example', 'http://example.com', 'mailto:a@b.c']);

it('refuses an image sharing its line, or with no description, naming its line', function (string $line) {
    expect(markTextRefusal("Fine\n{$line}"))->toBe([InvalidContentInput::IMAGE, 2]);
})->with(['See ![a door](image:2) here', '![](image:2)', '![   ](image:2)']);

it('refuses a table row of another width: "Line 3: the table has 3 columns here and 2 above."', function () {
    try {
        MarkText::blocks('body', "| a | b |\n| 1 | 2 |\n| 1 | 2 | 3 |");
    } catch (InvalidContentInput $refused) {
        expect($refused->rule)->toBe(InvalidContentInput::TABLE_ROW_COLUMNS)
            ->and($refused->context())->toMatchArray(['line' => 3, 'found' => 3, 'expected' => 2, 'rule' => 'table_row_columns_line']);

        return;
    }

    throw new LogicException('Not refused.');
});

it('names the line a document rule is broken on, from the block it starts', function () {
    // A table of one column is the document's rule (2 to 8), said on the line the table starts.
    expect(markTextRefusal("Intro\n\n| only |\n| one |"))->toBe([InvalidContentInput::TABLE_COLUMNS, 3]);
});

it('writes each block back as its marks', function () {
    $text = RichText::of('body', ['blocks' => [
        ['type' => 'heading', 'level' => 3, 'runs' => [['text' => 'Tools']]],
        ['type' => 'paragraph', 'runs' => [['text' => 'See '], ['text' => 'guide', 'bold' => true, 'link' => '/pages/guide']]],
        ['type' => 'list', 'ordered' => true, 'items' => [[['text' => 'a']], [['text' => 'b']]]],
        ['type' => 'image', 'ref' => 7, 'alt' => 'A door'],
        ['type' => 'table', 'head' => [[['text' => 'x']], [['text' => 'y']]], 'rows' => [[[], [['text' => '1']]]]],
    ]]);

    expect(MarkText::marks($text))->toBe("## Tools\n\nSee **[guide](/pages/guide)**\n\n1. a\n2. b\n\n![A door](image:7)\n\n| x | y |\n|  | 1 |");
});

it('gives back a typed text saved unchanged exactly as it was', function (string $typed) {
    $blocks = MarkText::blocks('body', $typed);

    // The form shows the written marks, the person saves them unchanged: the same document is kept.
    expect(MarkText::marks($blocks))->toBe($typed)
        ->and(MarkText::blocks('body', MarkText::marks($blocks))->equals($blocks))->toBeTrue();
})->with([
    'every kind' => ["# Installation\n\n## Tools\n\nFits **every** cabinet, see [the guide](/pages/guide).\n\n- Soft close\n- **Tool-free**\n\n1. Measure\n2. Fit\n\n![The cabinet open](image:3)\n\n| Size | Price |\n| 60 |  |"],
    'Arabic' => ["# التركيب\n\nيناسب **كل** خزانة، راجع [الدليل](/pages/guide).\n\n- إغلاق هادئ"],
    'a mark left open' => ['a **b'],
    'bold beside bold-looking text' => ['a***b**'],
    'a bold link' => ['**[Shop now](https://example.com/sale)** today'],
]);

it('gives back a document read from typed text as the same document, however it was typed', function (string $typed) {
    $blocks = MarkText::blocks('body', $typed);

    expect(MarkText::blocks('body', MarkText::marks($blocks))->equals($blocks))->toBeTrue();
})->with([
    'lines joined into a paragraph' => ["one\ntwo\n\n\n\nthree"],
    'spaces around' => ["   # Title   \n\n  - a  "],
    'numbers not in order' => ["7. a\n3. b"],
    'a link with no slash' => ['[x](categories/chairs)'],
    'Arabic-Indic digits' => ['السعر ١٥٠'],
]);

it('reads nothing typed as an empty document', function () {
    expect(MarkText::blocks('body', '')->isEmpty())->toBeTrue()
        ->and(MarkText::blocks('body', "\n  \n")->isEmpty())->toBeTrue()
        ->and(MarkText::marks(RichText::empty()))->toBe('');
});
