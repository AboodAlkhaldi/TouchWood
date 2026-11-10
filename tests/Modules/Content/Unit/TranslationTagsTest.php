<?php

declare(strict_types=1);

use Modules\Content\Application\Text\MarkText;
use Modules\Content\Application\Text\TranslationPieces;
use Modules\Content\Application\Text\TranslationTags;
use Modules\Content\Domain\ValueObject\RichText;

/*
| Translate keeps bold and links (content.md §1.3 (a)): a line's runs as the XML sent with tag handling -
| `<b>`, `<a i="N">` with the address kept here - and read back strictly; anything but the tags sent, nested
| as sent, is no answer for the marks, and the box falls back to plain words.
*/

/**
 * The runs of the one paragraph a typed line is.
 *
 * @return list<array<string, mixed>>
 */
function translationTagsRuns(string $typed): array
{
    $block = MarkText::blocks('body', $typed)->toArray()['blocks'][0];
    $runs = $block['runs'] ?? null;

    if (! is_array($runs)) {
        throw new LogicException('Not a paragraph.');
    }

    /** @var list<array<string, mixed>> $runs */
    return $runs;
}

/**
 * A typed line sent, answered as the service would, and written back as typed: null when the answer is not
 * the tags sent.
 *
 * @param  Closure(string): string  $service  what the service makes of the XML sent
 */
function translationTagsRoundTrip(string $typed, Closure $service): ?string
{
    $sent = TranslationTags::encode(translationTagsRuns($typed));
    $read = TranslationTags::parse($service($sent['xml']), $sent['links'], $sent['bolds']);

    return $read === null ? null : MarkText::marks(RichText::of('body', ['blocks' => [['type' => 'paragraph', 'runs' => $read]]]));
}

it('writes bold as <b> and a link as <a i="N">, its address kept here and never in the XML', function (string $typed, string $xml, array $links, int $bolds) {
    $sent = TranslationTags::encode(translationTagsRuns($typed));

    expect($sent)->toBe(['xml' => $xml, 'links' => $links, 'bolds' => $bolds]);

    foreach ($links as $address) {
        expect($sent['xml'])->not->toContain($address);
    }
})->with([
    'bold' => ['خزانة **خشب الزان** مع **ضمان سنتين**.', 'خزانة <b>خشب الزان</b> مع <b>ضمان سنتين</b>.', [], 2],
    'a link' => ['راجع [الدليل](/pages/guide) أولا', 'راجع <a i="0">الدليل</a> أولا', ['/pages/guide'], 0],
    'a bold link' => ['اقرأ **[الضمان](https://example.com/warranty)**', 'اقرأ <a i="0"><b>الضمان</b></a>', ['https://example.com/warranty'], 1],
    'bold inside a link' => ['[دليل ](/pages/fit)**[التركيب](/pages/fit)**[ الكامل](/pages/fit)', '<a i="0">دليل <b>التركيب</b> الكامل</a>', ['/pages/fit'], 1],
    'two links, the same address twice' => ['[أ](/pages/a) و [ب](/pages/a)', '<a i="0">أ</a> و <a i="1">ب</a>', ['/pages/a', '/pages/a'], 0],
    'mixed, English words inside Arabic' => ['**TouchWood** يقدم [Soft Close](/pages/soft) و **ضمان**', '<b>TouchWood</b> يقدم <a i="0">Soft Close</a> و <b>ضمان</b>', ['/pages/soft'], 2],
]);

it('writes &, < and > in the words as XML\'s entities, so the service reads them as words', function () {
    expect(TranslationTags::encode(translationTagsRuns('5 < 10 & **أ > ب** <b>نص</b>'))['xml'])
        ->toBe('5 &lt; 10 &amp; <b>أ &gt; ب</b> &lt;b&gt;نص&lt;/b&gt;')
        ->and(TranslationTags::escape('A & B <i>'))->toBe('A &amp; B &lt;i&gt;');
});

it('reads back each mark the service kept, a link\'s address by its index', function (string $typed) {
    // A service that changes nothing: the line comes back as it went.
    expect(translationTagsRoundTrip($typed, static fn (string $xml): string => $xml))->toBe($typed);
})->with([
    'bold' => ['خزانة **خشب الزان** مع **ضمان سنتين**.'],
    'a link' => ['راجع [الدليل](/pages/guide) أولا'],
    'a bold link' => ['اقرأ **[الضمان](https://example.com/warranty)**'],
    'bold inside a link' => ['[دليل ](/pages/fit)**[التركيب](/pages/fit)**[ الكامل](/pages/fit)'],
    'mixed' => ['**TouchWood** يقدم [Soft Close](/pages/soft) و **ضمان**'],
    'escaped words' => ['5 < 10 & **أ > ب** <b>نص</b>'],
]);

it('keeps the marks on the right words when the service moves them, as the real account did (2026-11-10)', function () {
    $answer = static fn (): string => '<b>Beech wood</b> cabinet with <b>a two-year warranty</b>.';

    expect(translationTagsRoundTrip('خزانة **خشب الزان** مع **ضمان سنتين**.', $answer))->toBe('**Beech wood** cabinet with **a two-year warranty**.')
        // Links swapped in place: each keeps its own address, by its index.
        ->and(translationTagsRoundTrip('[أ](/pages/a) و [ب](/pages/b)', static fn (): string => '<a i="1">B</a> and <a i="0">A</a>'))
        ->toBe('[B](/pages/b) and [A](/pages/a)')
        // The service's own entities and character references read as the characters they are.
        ->and(translationTagsRoundTrip('**أ** و ب', static fn (): string => '<b>A</b> &amp; B &lt;3 &#66; &#x42; &quot;&apos; >'))
        ->toBe('**A** & B <3 B B "\' >');
});

it('keeps an answer on one line, its edges trimmed', function () {
    $sent = TranslationTags::encode(translationTagsRuns('**أ** ب'));

    expect(TranslationTags::parse(" \n<b>A</b>\nB\n ", $sent['links'], $sent['bolds']))->toBe([['text' => 'A', 'bold' => true], ['text' => ' B']])
        ->and(TranslationTags::parse('<b> </b> ', $sent['links'], $sent['bolds']))->toBeNull();
});

it('refuses any answer that is not the tags sent, nested as sent', function (string $typed, string $answer) {
    $sent = TranslationTags::encode(translationTagsRuns($typed));

    expect(TranslationTags::parse($answer, $sent['links'], $sent['bolds']))->toBeNull();
})->with([
    'a tag not sent' => ['**أ** ب', '<b>A</b> <i>B</i>'],
    'a script' => ['**أ** ب', '<b>A</b> <script>alert(1)</script>'],
    'a link tag in a line with none' => ['**أ** ب', '<b>A</b> <a i="0">B</a>'],
    'a link index not sent' => ['[أ](/pages/a) ب', '<a i="1">A</a> B'],
    'a link index written otherwise' => ['[أ](/pages/a) ب', '<a i="00">A</a> B'],
    'a link index twice' => ['[أ](/pages/a) و [ب](/pages/b)', '<a i="0">A</a> and <a i="0">B</a>'],
    'a link index twice, one sent' => ['[أ](/pages/a) ب', '<a i="0">A</a> and <a i="0">B</a>'],
    'a link lost' => ['[أ](/pages/a) و [ب](/pages/b)', '<a i="0">A</a> and B'],
    'a link with an address' => ['[أ](/pages/a) ب', '<a i="0" href="javascript:alert(1)">A</a> B'],
    'a link written another way' => ['[أ](/pages/a) ب', '<a href="/pages/a">A</a> B'],
    'a bold lost' => ['**أ** و **ب**', '<b>A</b> and B'],
    'a bold added' => ['**أ** و ب', '<b>A</b> and <b>B</b>'],
    'a link inside a bold' => ['**[أ](/pages/a)** ب', '<b><a i="0">A</a></b> B'],
    'a link opened inside a bold, closed outside it' => ['**[أ](/pages/a)** ب', '<b><a i="0">A</b></a> B'],
    'a bold inside a bold' => ['**أ** و **ب**', '<b>A <b>B</b></b>'],
    'a link inside a link' => ['[أ](/pages/a) و [ب](/pages/b)', '<a i="0">A <a i="1">B</a></a>'],
    'a bold left open' => ['**أ** ب', '<b>A B'],
    'a bold closed twice' => ['**أ** ب', '<b>A</b> B</b>'],
    'a link closed with its bold open' => ['**[أ](/pages/a)** ب', '<a i="0"><b>A</a></b> B'],
    'a bold of no words' => ['**أ** ب', '<b></b>A B'],
    'a bold written short' => ['**أ** ب', '<b/>A B'],
    'a bare <' => ['**أ** ب', '<b>A</b> 5 < 10'],
    'a bare &' => ['**أ** ب', '<b>A</b> R&D'],
    'an entity XML has not' => ['**أ** ب', '<b>A</b>&nbsp;B'],
    'a character reference to nothing' => ['**أ** ب', '<b>A</b> &#0; B'],
    'a comment' => ['**أ** ب', '<b>A</b> <!-- B -->'],
    'a CDATA section' => ['**أ** ب', '<b>A</b> <![CDATA[B]]>'],
]);

it('gives the words alone for a box that falls back: every tag out, entities read, none a mark', function () {
    expect(TranslationTags::plain('<b>Beech</b> wood <a i="9">cabinet</a> <script>x</script> &amp; &lt;b&gt;'))
        ->toBe('Beech wood cabinet x & <b>');
});

it('rebuilds a whole document with its marks, or gives nothing when one piece\'s answer is wrong', function () {
    $text = MarkText::blocks('body', "# **التركيب**\n\nراجع [الدليل](/pages/guide).\n\n- **بلا أدوات**\n\n![الخزانة](image:3)\n\n| **المقاس** | السعر |\n| 60 |  |");
    $cut = TranslationPieces::of($text);

    expect($cut['tagged'])->toBe(['<b>التركيب</b>', 'راجع <a i="0">الدليل</a>.', '<b>بلا أدوات</b>', 'الخزانة', '<b>المقاس</b>', 'السعر', '60'])
        ->and($cut['marked'])->toBe([true, true, true, false, true, false, false]);

    $answers = ['<b>Fitting</b>', 'See <a i="0">the guide</a>.', '<b>No tools</b>', 'The cabinet', '<b>Size</b>', 'Price', '60'];
    $rebuilt = TranslationPieces::rebuildMarked('body', $text, $answers);

    expect($rebuilt === null ? null : MarkText::marks($rebuilt))
        ->toBe("# **Fitting**\n\nSee [the guide](/pages/guide).\n\n- **No tools**\n\n![The cabinet](image:3)\n\n| **Size** | Price |\n| 60 |  |");

    // One piece wrong is the whole box's fallback.
    $answers[4] = '<b>Size</b> <b>extra</b>';
    expect(TranslationPieces::rebuildMarked('body', $text, $answers))->toBeNull();
});
