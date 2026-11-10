<?php

declare(strict_types=1);

use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Database\Seeders\PlatformSeeder;
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
use Shared\Application\Unauthorized;
use Tests\Modules\Access\Support\AccessFixtures as Fx;

use function Pest\Laravel\seed;

/*
| Translate (content.md §1.3, §3): by kind and store, never saving; the token, the caps of a press, of a
| minute and of a day; block by block, the marks dropped until the real account says (§1.3 (b), PARKED);
| each failure in its own words, the text in the box kept. Against the fake translator: no request leaves.
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

it('sends a rich text block by block, keeps its structure and images, and counts the blocks that lost marks', function () {
    translateAsEditorOf(ContentPermissions::PAGE_MANAGE);
    $typed = "# التركيب\n\nيناسب **كل** خزانة، راجع [الدليل](/pages/guide).\n\n- إغلاق هادئ\n- **بلا أدوات**\n\n![الخزانة مفتوحة](image:3)\n\n| المقاس | السعر |\n| 60 |  |";

    $translated = translatePress('page', Fx::storeId('sa'), [['text' => $typed, 'rich' => true]]);

    // Each heading, paragraph, list item, cell and description its own text; no address and no image ref sent.
    expect(translateFake()->calls()[0]['texts'])->toBe(['التركيب', 'يناسب كل خزانة، راجع الدليل.', 'إغلاق هادئ', 'بلا أدوات', 'الخزانة مفتوحة', 'المقاس', 'السعر', '60'])
        ->and(implode(' ', translateFake()->calls()[0]['texts']))->not->toContain('/pages/guide')
        ->and($translated->texts[0])->toBe("# [en] التركيب\n\n[en] يناسب كل خزانة، راجع الدليل.\n\n- [en] إغلاق هادئ\n- [en] بلا أدوات\n\n![[en] الخزانة مفتوحة](image:3)\n\n| [en] المقاس | [en] السعر |\n| [en] 60 |  |")
        // The paragraph's bold and link and the item's bold, gone plain (§1.3 (b)).
        ->and($translated->lostMarks)->toBe([2]);
});

it('counts every box of Translate All together, in one call', function () {
    translateAsEditorOf(ContentPermissions::BLOG_MANAGE);

    $translated = translatePress('blog', Fx::storeId('sa'), [['text' => 'عنوان', 'rich' => false], ['text' => "فقرة\n\n- بند", 'rich' => true]]);

    expect(translateFake()->calls())->toHaveCount(1)
        ->and(translateFake()->calls()[0]['texts'])->toBe(['عنوان', 'فقرة', 'بند'])
        ->and($translated->texts)->toBe(['[en] عنوان', "[en] فقرة\n\n- [en] بند"]);
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
