<?php

declare(strict_types=1);

use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Database\Seeders\PlatformSeeder;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Modules\Content\Application\Command\TranslateText\TranslatedText;
use Modules\Content\Application\Command\TranslateText\TranslateText;
use Modules\Content\Application\Command\TranslateText\TranslateTextHandler;
use Modules\Content\Application\ContentPermissions;
use Modules\Content\Application\Text\TranslationLimits;
use Modules\Content\Domain\Exception\InvalidContentInput;
use Modules\Content\Domain\Exception\StoreOff;
use Modules\Content\Domain\Exception\TranslationUnavailable;
use Modules\Platform\Application\Query\StoreDirectory;
use Modules\Platform\Infrastructure\External\DeepLTextTranslator;
use Modules\Platform\Infrastructure\External\FakeTextTranslator;
use Modules\Platform\Public\Contracts\TextTranslator;
use Modules\Platform\Public\Enums\TranslationFailure;
use Psr\Http\Message\RequestInterface;
use Shared\Application\Unauthorized;
use Tests\Modules\Access\Support\AccessFixtures as Fx;

use function Pest\Laravel\seed;

/*
| Translate (content.md §1.3, §3): by kind and store, never saving; the token, the caps of a press, of a
| minute and of a day; block by block, bold and links kept as tags (§1.3 (a)), a box whose answer loses
| them falling back to plain words; each failure in its own words, the text in the box kept. Against the
| fake translator or a stand-in for DeepL: no request leaves.
*/

uses(RefreshDatabase::class);

beforeEach(function () {
    seed(PlatformSeeder::class);
});

/** Moves both clocks on: the limiter's cache reads the mutable one, the code the immutable one. */
function translateClockOn(int $seconds): void
{
    $moment = CarbonImmutable::now()->addSeconds($seconds);
    CarbonImmutable::setTestNow($moment);
    Carbon::setTestNow($moment);
}

function translateFake(): FakeTextTranslator
{
    return app(FakeTextTranslator::class);
}

/**
 * @param  list<array{text: string, rich: bool}>  $boxes
 */
function translatePress(string $kind, ?string $store, array $boxes): TranslatedText
{
    return app(TranslateTextHandler::class)->handle(new TranslateText($kind, $store, $boxes));
}

/** Acting as a staff member holding the permission in Saudi Arabia, and nothing else. */
function translateAsEditorOf(string $permission): void
{
    Fx::actAsStaff(Fx::staffWith([$permission], ['sa']));
}

/**
 * The reason a press is refused with.
 *
 * @param  list<array{text: string, rich: bool}>  $boxes
 */
function translateRefusal(string $kind, ?string $store, array $boxes): string
{
    try {
        translatePress($kind, $store, $boxes);
    } catch (TranslationUnavailable $refused) {
        return $refused->reason;
    }

    throw new LogicException('Not refused.');
}

it('gives back each box\'s English and saves nothing', function () {
    translateAsEditorOf(ContentPermissions::BANNER_MANAGE);
    $before = DB::table('platform.audit_entries')->count();

    $translated = translatePress('banner', Fx::storeId('sa'), [['text' => 'تخفيضات الصيف', 'rich' => false], ['text' => 'تسوق الآن', 'rich' => false]]);

    expect($translated->texts)->toBe(['[en] تخفيضات الصيف', '[en] تسوق الآن'])
        ->and($translated->lostMarks)->toBe([0, 0])
        ->and(translateFake()->calls())->toHaveCount(1)
        ->and(translateFake()->calls()[0])->toMatchArray(['from' => 'ar', 'to' => 'en', 'keepMarks' => false])
        ->and(DB::table('platform.audit_entries')->count())->toBe($before);
});

it('sends a rich text block by block with its bold and links as tags, and keeps its structure, images and marks', function () {
    translateAsEditorOf(ContentPermissions::PAGE_MANAGE);
    $typed = "# التركيب\n\nيناسب **كل** خزانة، راجع [الدليل](/pages/guide).\n\n- إغلاق هادئ\n- **بلا أدوات**\n\n![الخزانة مفتوحة](image:3)\n\n| المقاس | السعر |\n| 60 |  |";

    $translated = translatePress('page', Fx::storeId('sa'), [['text' => $typed, 'rich' => true]]);

    // Each heading, paragraph, list item, cell and description its own text, as XML with tag handling (§1.3 (a));
    // no address and no image ref sent.
    expect(translateFake()->calls()[0]['texts'])->toBe(['التركيب', 'يناسب <b>كل</b> خزانة، راجع <a i="0">الدليل</a>.', 'إغلاق هادئ', '<b>بلا أدوات</b>', 'الخزانة مفتوحة', 'المقاس', 'السعر', '60'])
        ->and(translateFake()->calls()[0]['keepMarks'])->toBeTrue()
        ->and(implode(' ', translateFake()->calls()[0]['texts']))->not->toContain('/pages/guide')
        // The fake's own "[en]" written escaped, as any bracket in a rich text is (the round trip, PR 2 review),
        // outside the tags: the bold and the link come back on their words.
        ->and($translated->texts[0])->toBe('# \[en\] التركيب'."\n\n".'\[en\] يناسب **كل** خزانة، راجع [الدليل](/pages/guide).'."\n\n".'- \[en\] إغلاق هادئ'."\n".'- \[en\] **بلا أدوات**'."\n\n".'![\[en\] الخزانة مفتوحة](image:3)'."\n\n".'| \[en\] المقاس | \[en\] السعر |'."\n".'| \[en\] 60 |  |')
        ->and($translated->lostMarks)->toBe([0]);
});

/**
 * A translator answering each text as `$answer` makes it, and keeping what it was sent.
 *
 * @param  Closure(string): string  $answer
 * @param  ArrayObject<int, array{texts: list<string>, keepMarks: bool}>  $calls
 */
function translateAnswering(Closure $answer, ArrayObject $calls): TextTranslator
{
    return new class($answer, $calls) implements TextTranslator
    {
        /**
         * @param  Closure(string): string  $answer
         * @param  ArrayObject<int, array{texts: list<string>, keepMarks: bool}>  $calls
         */
        public function __construct(private Closure $answer, private ArrayObject $calls) {}

        public function isReady(): bool
        {
            return true;
        }

        public function translate(array $texts, string $from, string $to, bool $keepMarks = false): array
        {
            $this->calls->append(['texts' => $texts, 'keepMarks' => $keepMarks]);

            return array_map($this->answer, $texts);
        }
    };
}

it('keeps bold, a link, a bold link and bold inside a link through the real service\'s kind of answer, in English', function () {
    translateAsEditorOf(ContentPermissions::PAGE_MANAGE);
    $calls = new ArrayObject;
    // The live test's answer (2026-11-10), the words moved round their tags; the rest answered in English.
    app()->instance(TextTranslator::class, translateAnswering(static fn (string $xml): string => match ($xml) {
        'خزانة <b>خشب الزان</b> مع <b>ضمان سنتين</b>.' => '<b>Beech wood</b> cabinet with <b>a two-year warranty</b>.',
        'اقرأ <a i="0"><b>الضمان</b></a> و<a i="1">الدليل</a>' => 'Read <a i="0"><b>the warranty</b></a> and <a i="1">the guide</a>',
        '<a i="0">دليل <b>التركيب</b> الكامل</a>' => '<a i="0">The full <b>fitting</b> guide</a>',
        default => throw new LogicException("Not expected: {$xml}"),
    }, $calls));
    $typed = "خزانة **خشب الزان** مع **ضمان سنتين**.\n\nاقرأ **[الضمان](https://example.com/warranty)** و[الدليل](/pages/guide)\n\n[دليل ](/pages/fit)**[التركيب](/pages/fit)**[ الكامل](/pages/fit)";

    $translated = translatePress('page', Fx::storeId('sa'), [['text' => $typed, 'rich' => true]]);

    expect($translated->texts)->toBe(["**Beech wood** cabinet with **a two-year warranty**.\n\nRead **[the warranty](https://example.com/warranty)** and [the guide](/pages/guide)\n\n[The full ](/pages/fit)**[fitting](/pages/fit)**[ guide](/pages/fit)"])
        ->and($translated->lostMarks)->toBe([0])
        ->and(array_column($calls->getArrayCopy(), 'keepMarks'))->toBe([true]);
});

it('writes <, > and & in the words as XML\'s entities when marks go, and gives them back as the characters they are', function () {
    translateAsEditorOf(ContentPermissions::PAGE_MANAGE);

    $translated = translatePress('page', Fx::storeId('sa'), [
        ['text' => 'من 5 < 10 & **أكثر > 3** <b>', 'rich' => true],
        // A plain box in the same call: sent as XML too, as the service reads every text of the call so.
        ['text' => 'أ & ب <i>', 'rich' => false],
    ]);

    expect(translateFake()->calls()[0]['texts'])->toBe(['من 5 &lt; 10 &amp; <b>أكثر &gt; 3</b> &lt;b&gt;', 'أ &amp; ب &lt;i&gt;'])
        ->and($translated->texts)->toBe(['\[en\] من 5 < 10 & **أكثر > 3** <b>', '[en] أ & ب <i>'])
        ->and($translated->lostMarks)->toBe([0, 0]);
});

it('sends a press with no bold or link as plain text, as before, its words as typed', function () {
    translateAsEditorOf(ContentPermissions::PAGE_MANAGE);

    $translated = translatePress('page', Fx::storeId('sa'), [['text' => 'من 5 < 10 & أكثر', 'rich' => true], ['text' => 'أ & ب', 'rich' => false]]);

    expect(translateFake()->calls()[0])->toMatchArray(['texts' => ['من 5 < 10 & أكثر', 'أ & ب'], 'keepMarks' => false])
        ->and($translated->texts)->toBe(['\[en\] من 5 < 10 & أكثر', '[en] أ & ب']);
});

it('drops the marks of a box whose answer is not the tags sent, and only that box\'s, and the press goes on', function (string $broken, string $words) {
    translateAsEditorOf(ContentPermissions::PAGE_MANAGE);
    $calls = new ArrayObject;
    app()->instance(TextTranslator::class, translateAnswering(static fn (string $xml): string => match ($xml) {
        'خزانة <b>خشب</b> و<a i="0">دليل</a>' => $broken,
        'خزانة <b>ثانية</b>' => 'A <b>second</b> cabinet',
        'عنوان &amp; وصف' => 'Title &amp; description',
        default => throw new LogicException("Not expected: {$xml}"),
    }, $calls));

    $translated = translatePress('page', Fx::storeId('sa'), [
        ['text' => "خزانة **خشب** و[دليل](/pages/guide)\n\nخزانة **خشب** و[دليل](/pages/guide)", 'rich' => true],
        ['text' => 'خزانة **ثانية**', 'rich' => true],
        ['text' => 'عنوان & وصف', 'rich' => false],
    ]);

    // The first box's two blocks as plain words, exactly: no mark, and no tag of the answer made one.
    expect($translated->texts[0])->toBe($words."\n\n".$words)
        ->and($translated->lostMarks)->toBe([2, 0, 0])
        // The other boxes as they came.
        ->and(array_slice($translated->texts, 1))->toBe(['A **second** cabinet', 'Title & description']);
})->with([
    'the tags lost' => ['Wood cabinet and guide', 'Wood cabinet and guide'],
    'a tag not sent' => ['<b>Wood</b> cabinet and <a i="0">guide</a> <script>alert(1)</script>', 'Wood cabinet and guide alert(1)'],
    'a link index changed' => ['<b>Wood</b> cabinet and <a i="1">guide</a>', 'Wood cabinet and guide'],
    'a link given an address' => ['<b>Wood</b> cabinet and <a i="0" href="javascript:alert(1)">guide</a>', 'Wood cabinet and guide'],
    'nested otherwise' => ['<b>Wood <a i="0">guide</a></b> cabinet', 'Wood guide cabinet'],
    'not XML' => ['<b>Wood cabinet & <a i="0">guide', 'Wood cabinet & guide'],
    'a link of spaces trimmed away' => ['<b>Wood</b> cabinet and guide <a i="0"> </a>', 'Wood cabinet and guide'],
]);

it('keeps the marks when the service splits a bold or gives a link two places, each place with our address', function () {
    translateAsEditorOf(ContentPermissions::PAGE_MANAGE);
    app()->instance(TextTranslator::class, translateAnswering(static fn (string $xml): string => match ($xml) {
        'خزانة <b>خشب الزان</b> و<a i="0">دليل</a>' => '<b>Beech</b> <b>wood</b> <a i="0">cabinet</a>, see the <a i="0">guide</a>',
        default => throw new LogicException("Not expected: {$xml}"),
    }, new ArrayObject));

    $translated = translatePress('page', Fx::storeId('sa'), [['text' => 'خزانة **خشب الزان** و[دليل](/pages/guide)', 'rich' => true]]);

    expect($translated->texts)->toBe(['**Beech** **wood** [cabinet](/pages/guide), see the [guide](/pages/guide)'])
        ->and($translated->lostMarks)->toBe([0]);
});

it('checks an answer of no words on its words, not its XML: a rich box falls back, keeping that block\'s Arabic', function () {
    translateAsEditorOf(ContentPermissions::PAGE_MANAGE);
    app()->instance(TextTranslator::class, translateAnswering(static fn (string $xml): string => match ($xml) {
        // Tags and an entity, and no words.
        'خزانة <b>خشب</b>' => '<b>&#32;</b> ',
        'فقرة <a i="0">ثانية</a>' => 'A <a i="0">second</a> paragraph',
        'عنوان' => 'Title',
        default => throw new LogicException("Not expected: {$xml}"),
    }, new ArrayObject));

    $translated = translatePress('page', Fx::storeId('sa'), [
        ['text' => "خزانة **خشب**\n\nفقرة [ثانية](/pages/b)", 'rich' => true],
        ['text' => 'عنوان', 'rich' => false],
    ]);

    // The box's marks dropped and counted; the block with no words keeps its Arabic for the person to type over.
    expect($translated->texts)->toBe(["خزانة خشب\n\nA second paragraph", 'Title'])
        ->and($translated->lostMarks)->toBe([2, 0]);
});

it('refuses a plain box\'s answer of no words in a call sent as XML, as any empty answer is refused', function (string $answer) {
    translateAsEditorOf(ContentPermissions::PAGE_MANAGE);
    app()->instance(TextTranslator::class, translateAnswering(static fn (string $xml): string => match ($xml) {
        'خزانة <b>خشب</b>' => '<b>Wood</b> cabinet',
        'عنوان' => $answer,
        default => throw new LogicException("Not expected: {$xml}"),
    }, new ArrayObject));

    expect(translateRefusal('page', Fx::storeId('sa'), [['text' => 'خزانة **خشب**', 'rich' => true], ['text' => 'عنوان', 'rich' => false]]))
        ->toBe(TranslationUnavailable::UNREACHABLE);
})->with([
    'a tag alone' => ['<x/>'],
    'an entity of a space' => ['&#32;'],
]);

it('sends a plain box\'s control characters as spaces in a call sent as XML, which XML 1.0 forbids', function () {
    translateAsEditorOf(ContentPermissions::PAGE_MANAGE);

    translatePress('page', Fx::storeId('sa'), [['text' => 'خزانة **خشب**', 'rich' => true], ['text' => "أ\x0Bب\x01ج", 'rich' => false]]);

    expect(translateFake()->calls()[0]['texts'])->toBe(['خزانة <b>خشب</b>', 'أ ب ج']);
});

it('sends no link\'s address to the service, and gives it back on the answer\'s words (DeepL over its stand-in)', function () {
    translateAsEditorOf(ContentPermissions::PAGE_MANAGE);
    $sent = new ArrayObject;
    $stack = HandlerStack::create(new MockHandler([new Response(200, ['Content-Type' => 'application/json'], (string) json_encode([
        'translations' => [['detected_source_language' => 'AR', 'text' => 'Read <a i="0">the <b>warranty</b> terms</a> first.']],
    ]))]));
    $stack->push(Middleware::history($sent));
    app()->instance(TextTranslator::class, new DeepLTextTranslator('secret-token-for-tests-1234:fx', new Client(['handler' => $stack])));

    $translated = translatePress('page', Fx::storeId('sa'), [['text' => 'اقرأ [شروط ](/pages/warranty-terms)**[الضمان](/pages/warranty-terms)** أولا.', 'rich' => true]]);

    $request = $sent[0]['request'] ?? null;
    expect($request)->toBeInstanceOf(RequestInterface::class);
    assert($request instanceof RequestInterface);
    $raw = (string) $request->getBody();
    $body = json_decode($raw, true);

    expect($body['text'] ?? null)->toBe(['اقرأ <a i="0">شروط <b>الضمان</b></a> أولا.'])
        ->and($body['tag_handling'] ?? null)->toBe('xml')
        // Neither the address nor any part of it, however the library writes the slashes.
        ->and($raw)->not->toContain('warranty-terms')
        ->and($raw)->not->toContain('pages')
        ->and($translated->texts)->toBe(['Read [the ](/pages/warranty-terms)**[warranty](/pages/warranty-terms)**[ terms](/pages/warranty-terms) first.'])
        ->and($translated->lostMarks)->toBe([0]);
});

it('counts the tags in the request\'s bytes, so a press whose words fit but whose tags do not sends nothing', function () {
    translateAsEditorOf(ContentPermissions::PAGE_MANAGE);
    // 9,000 bold letters: 18,000 characters and about 63 KB of words, but about 135 KB once tagged.
    $marked = trim(str_repeat('**ب** ', 9_000));
    $plain = trim(str_repeat('ب ', 9_000));

    expect(translateRefusal('page', Fx::storeId('sa'), [['text' => $marked, 'rich' => true]]))->toBe(TranslationUnavailable::TOO_LONG)
        ->and(translateFake()->calls())->toBe([])
        ->and(translatePress('page', Fx::storeId('sa'), [['text' => $plain, 'rich' => true]])->texts)->toHaveCount(1);
});

it('counts a press\'s characters on its words, never on the tags that carry its marks (the build\'s pick)', function () {
    translateAsEditorOf(ContentPermissions::PAGE_MANAGE);
    // Exactly 20,000 letters, all bold: the tags would make it 20,007 characters sent.
    $typed = '**'.str_repeat('ب', TranslationLimits::PER_PRESS).'**';

    expect(translatePress('page', Fx::storeId('sa'), [['text' => $typed, 'rich' => true]])->lostMarks)->toBe([0])
        ->and(translateFake()->calls()[0]['texts'][0])->toBe('<b>'.str_repeat('ب', TranslationLimits::PER_PRESS).'</b>');
});

it('counts every box of Translate All together, in one call', function () {
    translateAsEditorOf(ContentPermissions::BLOG_MANAGE);

    $translated = translatePress('blog', Fx::storeId('sa'), [['text' => 'عنوان', 'rich' => false], ['text' => "فقرة\n\n- بند", 'rich' => true]]);

    expect(translateFake()->calls())->toHaveCount(1)
        ->and(translateFake()->calls()[0]['texts'])->toBe(['عنوان', 'فقرة', 'بند'])
        ->and($translated->texts)->toBe(['[en] عنوان', '\[en\] فقرة'."\n\n".'- \[en\] بند']);
});

it('checks the kind\'s permission in the store, so an item not saved yet can be translated', function (string $kind, string $permission) {
    translateAsEditorOf($permission);

    expect(translatePress($kind, Fx::storeId('sa'), [['text' => 'نص', 'rich' => false]])->texts)->toBe(['[en] نص']);
})->with(array_map(
    static fn (string $kind, string $permission): array => [$kind, $permission],
    array_keys(array_diff_key(ContentPermissions::KINDS, ['theme' => true])),
    array_values(array_diff_key(ContentPermissions::KINDS, ['theme' => true])),
));

it('refuses a kind the person may not edit, another store, and a store id that is none', function () {
    translateAsEditorOf(ContentPermissions::BANNER_MANAGE);

    expect(fn () => translatePress('page', Fx::storeId('sa'), [['text' => 'نص', 'rich' => false]]))->toThrow(Unauthorized::class)
        ->and(fn () => translatePress('banner', Fx::storeId('ae'), [['text' => 'نص', 'rich' => false]]))->toThrow(Unauthorized::class)
        ->and(fn () => translatePress('banner', 'not-an-id', [['text' => 'نص', 'rich' => false]]))->toThrow(Unauthorized::class)
        ->and(fn () => translatePress('banner', null, [['text' => 'نص', 'rich' => false]]))->toThrow(Unauthorized::class)
        ->and(fn () => translatePress('nothing', Fx::storeId('sa'), [['text' => 'نص', 'rich' => false]]))->toThrow(InvalidContentInput::class)
        ->and(translateFake()->calls())->toBe([]);
});

it('keeps a theme\'s Translate to Super Admins, in every store at once', function () {
    translateAsEditorOf(ContentPermissions::BANNER_MANAGE);
    expect(fn () => translatePress('theme', null, [['text' => 'رمضان', 'rich' => false]]))->toThrow(Unauthorized::class);

    Fx::actAsStaff(Fx::staff(superAdmin: true));
    expect(translatePress('theme', null, [['text' => 'رمضان', 'rich' => false]])->texts)->toBe(['[en] رمضان']);
});

it('refuses an off store to whoever may not switch stores, and lets one who may prepare it', function () {
    // The store's id and its editor are taken while it is on: an off store is no store by its code (lesson 187).
    $eg = Fx::storeId('eg');
    $editor = Fx::staffWith([ContentPermissions::BANNER_MANAGE], ['eg']);
    DB::table('platform.stores')->where('id', $eg)->update(['is_active' => false]);
    app(StoreDirectory::class)->invalidate();

    Fx::actAsStaff($editor);
    expect(fn () => translatePress('banner', $eg, [['text' => 'نص', 'rich' => false]]))->toThrow(StoreOff::class)
        ->and(translateFake()->calls())->toBe([]);

    Fx::actAsStaff(Fx::staff(superAdmin: true));
    expect(translatePress('banner', $eg, [['text' => 'نص', 'rich' => false]])->texts)->toBe(['[en] نص']);
});

it('says the server has no token, and sends nothing', function () {
    translateAsEditorOf(ContentPermissions::BANNER_MANAGE);
    app()->instance(TextTranslator::class, new DeepLTextTranslator(''));

    expect(translateRefusal('banner', Fx::storeId('sa'), [['text' => 'نص', 'rich' => false]]))->toBe(TranslationUnavailable::NOT_SET);
});

/**
 * A dataset's boxes, read back into their shape.
 *
 * @param  array<mixed>  $raw
 * @return list<array{text: string, rich: bool}>
 */
function translateBoxes(array $raw): array
{
    $boxes = [];

    foreach ($raw as $box) {
        if (! is_array($box) || ! is_string($box['text'] ?? null) || ! is_bool($box['rich'] ?? null)) {
            throw new LogicException('Not a box.');
        }

        $boxes[] = ['text' => $box['text'], 'rich' => $box['rich']];
    }

    return $boxes;
}

it('refuses a press with no Arabic, or a rich text written wrong, before sending', function (array $boxes, string $rule) {
    translateAsEditorOf(ContentPermissions::PAGE_MANAGE);

    try {
        translatePress('page', Fx::storeId('sa'), translateBoxes($boxes));
    } catch (InvalidContentInput $refused) {
        expect($refused->rule)->toBe($rule)
            ->and(translateFake()->calls())->toBe([]);

        return;
    }

    throw new LogicException('Not refused.');
})->with([
    'no boxes' => [[], InvalidContentInput::REQUIRED],
    'an empty box' => [[['text' => 'نص', 'rich' => false], ['text' => '  ', 'rich' => false]], InvalidContentInput::REQUIRED],
    'a rich box of no words' => [[['text' => "| |  |\n| | |", 'rich' => true]], InvalidContentInput::REQUIRED],
    'a rich box with a script link' => [[['text' => '[x](javascript:alert(1))', 'rich' => true]], InvalidContentInput::LINK],
]);

it('sends nothing over 20,000 characters a press, Translate All counted as one', function () {
    translateAsEditorOf(ContentPermissions::PAGE_MANAGE);
    $half = str_repeat('ب', TranslationLimits::PER_PRESS / 2);

    expect(translatePress('page', Fx::storeId('sa'), [['text' => $half, 'rich' => false], ['text' => $half, 'rich' => false]])->texts)->toHaveCount(2)
        ->and(translateRefusal('page', Fx::storeId('sa'), [['text' => $half, 'rich' => false], ['text' => $half.'ب', 'rich' => false]]))->toBe(TranslationUnavailable::TOO_LONG)
        ->and(translateFake()->calls())->toHaveCount(1);
});

it('sends nothing past DeepL\'s 128 KiB as the library encodes it, within 20,000 characters (PR 2 review)', function () {
    translateAsEditorOf(ContentPermissions::PAGE_MANAGE);
    // A letter outside the basic plane is one character and twelve bytes as `😀`.
    $emoji = str_repeat("\u{1F600}", 11_000);

    expect(TranslationLimits::requestBytes([$emoji]))->toBeGreaterThan(TranslationLimits::MAX_REQUEST_BYTES)
        ->and(mb_strlen($emoji))->toBeLessThan(TranslationLimits::PER_PRESS)
        ->and(translateRefusal('page', Fx::storeId('sa'), [['text' => $emoji, 'rich' => false]]))->toBe(TranslationUnavailable::TOO_LONG)
        ->and(translateFake()->calls())->toBe([]);

    // Just under the bytes, the press goes.
    $fits = str_repeat("\u{1F600}", 10_000);
    expect(TranslationLimits::requestBytes([$fits]))->toBeLessThanOrEqual(TranslationLimits::MAX_REQUEST_BYTES)
        ->and(translatePress('page', Fx::storeId('sa'), [['text' => $fits, 'rich' => false]])->texts)->toHaveCount(1);
});

it('counts the encoded bytes as the library writes them: Arabic as six bytes a letter, every piece\'s quotes', function () {
    // The letter escaped as a backslash, a u and four hex digits: six bytes.
    expect(TranslationLimits::requestBytes(['ب']))->toBe(strlen('{"text":[""]}') + 6)
        ->and(TranslationLimits::requestBytes(['a', 'b']))->toBe(strlen('{"text":["a","b"]}'));
});

it('refuses a box that is not UTF-8, or past the bytes a box may hold, before reading it', function (string $text, string $rule) {
    translateAsEditorOf(ContentPermissions::PAGE_MANAGE);

    foreach ([false, true] as $rich) {
        try {
            translatePress('page', Fx::storeId('sa'), [['text' => $text, 'rich' => $rich]]);
            throw new LogicException('Not refused.');
        } catch (InvalidContentInput $refused) {
            expect($refused->rule)->toBe($rule)
                ->and($refused->category()->value)->toBe('INVALID');
        }
    }

    expect(translateFake()->calls())->toBe([]);
})->with([
    'not UTF-8' => ["\xC3\x28 نص", InvalidContentInput::FORMAT],
    'past the bytes' => [str_repeat('a', TranslateTextHandler::MAX_BYTES + 1), InvalidContentInput::TOO_LARGE],
]);

it('says "not set up" in production with nothing set, never a failure', function () {
    translateAsEditorOf(ContentPermissions::BANNER_MANAGE);
    app()->detectEnvironment(fn (): string => 'production');
    config(['services.deepl.driver' => null, 'services.deepl.key' => '']);

    try {
        expect(translateRefusal('banner', Fx::storeId('sa'), [['text' => 'نص', 'rich' => false]]))->toBe(TranslationUnavailable::NOT_SET);
    } finally {
        app()->detectEnvironment(fn (): string => 'testing');
    }
});

it('holds a person to 30 presses a minute, and lets them press again the next minute', function () {
    translateAsEditorOf(ContentPermissions::BAR_MANAGE);
    $store = Fx::storeId('sa');

    foreach (range(1, TranslationLimits::PRESSES_A_MINUTE) as $press) {
        translatePress('bar', $store, [['text' => "نص {$press}", 'rich' => false]]);
    }

    expect(translateRefusal('bar', $store, [['text' => 'نص', 'rich' => false]]))->toBe(TranslationUnavailable::BUSY)
        ->and(translateFake()->calls())->toHaveCount(TranslationLimits::PRESSES_A_MINUTE);

    // Another person is not held by the first one's presses.
    translateAsEditorOf(ContentPermissions::BAR_MANAGE);
    expect(translatePress('bar', $store, [['text' => 'نص', 'rich' => false]])->texts)->toBe(['[en] نص']);

    translateClockOn(61);
    expect(fn () => translatePress('bar', $store, [['text' => 'نص', 'rich' => false]]))->not->toThrow(TranslationUnavailable::class);
});

it('holds a person to 300,000 characters a day, a refused press costing none of it', function () {
    translateAsEditorOf(ContentPermissions::PAGE_MANAGE);
    $store = Fx::storeId('sa');
    $press = str_repeat('ب', TranslationLimits::PER_PRESS);
    $half = str_repeat('ب', TranslationLimits::PER_PRESS / 2);
    // 290,000 used: fourteen full presses and a half one.
    $presses = intdiv(TranslationLimits::CHARACTERS_A_DAY, TranslationLimits::PER_PRESS) - 1;

    foreach (range(1, $presses) as $count) {
        // Past the minute's limit by spreading the presses over the day.
        translateClockOn(120);
        translatePress('page', $store, [['text' => $press, 'rich' => false]]);
    }

    translatePress('page', $store, [['text' => $half, 'rich' => false]]);

    // A full press would pass 300,000: refused, and taken back off the count.
    expect(translateRefusal('page', $store, [['text' => $press, 'rich' => false]]))->toBe(TranslationUnavailable::DAILY_LIMIT);

    // So the half that fits still fits, to exactly 300,000; then not one more character.
    expect(translatePress('page', $store, [['text' => $half, 'rich' => false]])->texts)->toHaveCount(1)
        ->and(translateRefusal('page', $store, [['text' => 'ب', 'rich' => false]]))->toBe(TranslationUnavailable::DAILY_LIMIT);

    translateClockOn(86_400);
    expect(translatePress('page', $store, [['text' => 'ب', 'rich' => false]])->texts)->toBe(['[en] ب']);
});

it('names each failure in its own words, the service\'s never', function (TranslationFailure $failure, string $reason, string $words) {
    translateAsEditorOf(ContentPermissions::BANNER_MANAGE);
    translateFake()->failNext($failure);

    try {
        translatePress('banner', Fx::storeId('sa'), [['text' => 'نص', 'rich' => false]]);
    } catch (TranslationUnavailable $refused) {
        expect($refused->reason)->toBe($reason)
            ->and(trans("content::errors.translation_unavailable.rules.{$reason}", [], 'en'))->toBe($words);

        return;
    }

    throw new LogicException('Not refused.');
})->with([
    'the allowance used up' => [TranslationFailure::Quota, TranslationUnavailable::QUOTA, 'Failed to translate: this month\'s allowance is used up. Type the English instead.'],
    'too many requests' => [TranslationFailure::Busy, TranslationUnavailable::BUSY, 'Failed to translate: too many requests. Try again in a minute.'],
    'a bad token' => [TranslationFailure::Refused, TranslationUnavailable::REFUSED, 'Failed to translate. Type the English instead.'],
    'a timeout' => [TranslationFailure::Unreachable, TranslationUnavailable::UNREACHABLE, 'Failed to translate. Type the English instead.'],
]);

it('turns the answer\'s digits to 0-9, and keeps it on one line in a rich text', function () {
    translateAsEditorOf(ContentPermissions::PAGE_MANAGE);
    app()->instance(TextTranslator::class, new class implements TextTranslator
    {
        public function isReady(): bool
        {
            return true;
        }

        public function translate(array $texts, string $from, string $to, bool $keepMarks = false): array
        {
            return array_map(static fn (string $text): string => "Line one\nsize ١٢٠", $texts);
        }
    });

    expect(translatePress('page', Fx::storeId('sa'), [['text' => 'نص', 'rich' => false], ['text' => 'فقرة', 'rich' => true]])->texts)
        ->toBe(["Line one\nsize 120", 'Line one size 120']);
});

it('calls an empty answer no translation, and keeps the box as it is', function () {
    translateAsEditorOf(ContentPermissions::PAGE_MANAGE);
    app()->instance(TextTranslator::class, new class implements TextTranslator
    {
        public function isReady(): bool
        {
            return true;
        }

        public function translate(array $texts, string $from, string $to, bool $keepMarks = false): array
        {
            return array_map(static fn (): string => ' ', $texts);
        }
    });

    expect(translateRefusal('page', Fx::storeId('sa'), [['text' => 'نص', 'rich' => false]]))->toBe(TranslationUnavailable::UNREACHABLE);
});
