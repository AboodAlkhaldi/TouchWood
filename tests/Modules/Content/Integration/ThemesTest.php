<?php

declare(strict_types=1);

use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Database\Seeders\PlatformSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Modules\Content\Application\Command\DeleteTheme\DeleteTheme;
use Modules\Content\Application\Command\DeleteTheme\DeleteThemeHandler;
use Modules\Content\Application\Command\EndTheme\EndTheme;
use Modules\Content\Application\Command\EndTheme\EndThemeHandler;
use Modules\Content\Application\Command\SaveCampaign\SaveCampaign;
use Modules\Content\Application\Command\SaveCampaign\SaveCampaignHandler;
use Modules\Content\Application\Command\SaveTheme\SaveTheme;
use Modules\Content\Application\Command\SaveTheme\SaveThemeHandler;
use Modules\Content\Application\Command\ScheduleTheme\ScheduleTheme;
use Modules\Content\Application\Command\ScheduleTheme\ScheduleThemeHandler;
use Modules\Content\Application\Command\ThemeBackToDraft\ThemeBackToDraft;
use Modules\Content\Application\Command\ThemeBackToDraft\ThemeBackToDraftHandler;
use Modules\Content\Application\Command\UseCampaignDates\UseCampaignDates;
use Modules\Content\Application\Command\UseCampaignDates\UseCampaignDatesHandler;
use Modules\Content\Application\ContentPermissions;
use Modules\Content\Domain\Exception\ImageNotReady;
use Modules\Content\Domain\Exception\InvalidContentInput;
use Modules\Content\Domain\Exception\MissingEnglish;
use Modules\Content\Domain\Exception\NotDeletable;
use Modules\Content\Domain\Exception\ThemeClash;
use Modules\Content\Domain\Model\ThemePartKind;
use Modules\Content\Domain\Repository\Themes;
use Modules\Content\Infrastructure\Media\ContentMediaUsage;
use Modules\Content\Presentation\Http\ThemePreviewCookie;
use Shared\Application\Unauthorized;
use Tests\Modules\Access\Support\AccessFixtures as Fx;
use Tests\Modules\Content\Support\ContentFixtures as Cx;

use function Pest\Laravel\seed;

/*
| content.md §1.8, W5b: themes - the 11 parts only, artwork and words, never a colour (Q7 (a)) - a Super Admin's, for
| the stores they pick, each store reading the typed dates on its own clock (Q1 (a)); one live per store, a second
| refused naming the clash under each store's lock; Back to Draft, End Now, Delete; Use Campaign's Dates (Q6 (a));
| Preview Theme's signed cookie for this browser. How the shop draws a theme is PR 9's.
|
| Every helper here is named after this file's subject: a Pest file's functions are global.
*/

uses(RefreshDatabase::class);

beforeEach(function () {
    seed(PlatformSeeder::class);
    themesClock('2026-11-10 10:00:00+00');
});

afterEach(function () {
    CarbonImmutable::setTestNow();
    Carbon::setTestNow();
});

function themesClock(string $utc): void
{
    CarbonImmutable::setTestNow(CarbonImmutable::parse($utc));
    Carbon::setTestNow(CarbonImmutable::now());
}

function themesSuper(): string
{
    $id = Fx::staff(superAdmin: true);
    Fx::actAsStaff($id);

    return $id;
}

/**
 * A draft theme through its use case.
 *
 * @param  list<string>  $stores  codes
 * @param  list<array<string, mixed>>  $parts
 */
function themesDraft(array $stores = ['sa'], string $starts = '2027-02-18 00:00', string $ends = '2027-03-19 00:00', array $parts = [], ?string $nameEn = 'Ramadan'): string
{
    return app(SaveThemeHandler::class)->handle(new SaveTheme(null, 0, 'رمضان', $nameEn, $starts, $ends, array_map(Fx::storeId(...), $stores), $parts));
}

function themesVersion(string $id): int
{
    return (int) DB::table('content.themes')->where('id', $id)->value('version');
}

function themesSchedule(string $id): void
{
    app(ScheduleThemeHandler::class)->handle(new ScheduleTheme($id, themesVersion($id)));
}

function themesThrown(callable $work): Throwable
{
    try {
        $work();
    } catch (Throwable $thrown) {
        return $thrown;
    }

    throw new LogicException('Not refused.');
}

function themesPng(string $sizes = 'READY'): string
{
    return Cx::image($sizes, mime: 'image/png');
}

describe('who and what (§1.8; §3)', function () {
    it('is a Super Admin\'s alone, checked in each of its stores', function () {
        Cx::editor([ContentPermissions::CAMPAIGN_MANAGE, ContentPermissions::BANNER_MANAGE], ['sa']);

        expect(fn () => themesDraft())->toThrow(Unauthorized::class);

        themesSuper();
        $theme = themesDraft(['ae', 'sa']);

        expect(DB::table('content.theme_stores')->where('theme_id', $theme)->count())->toBe(2)
            ->and(DB::table('content.themes')->where('id', $theme)->value('slug'))->toBe('ramadan')
            // A second theme of the same name gets an address of its own; `base` is never one.
            ->and(DB::table('content.themes')->where('id', themesDraft())->value('slug'))->toBe('ramadan-2')
            ->and(DB::table('content.themes')->where('id', themesDraft(nameEn: 'Base'))->value('slug'))->toStartWith('theme-')
            ->and(Fx::audits('content.theme.created', $theme))->toBe(1);
    });

    it('reads the typed dates on each store\'s own clock (Q1 (a))', function () {
        themesSuper();
        $theme = themesDraft(['sa', 'ae']);
        $start = static fn (string $code): string => CarbonImmutable::parse((string) DB::table('content.theme_stores')->where('theme_id', $theme)->where('store_id', Fx::storeId($code))->value('starts_at'))->utc()->format('Y-m-d H:i');

        expect($start('sa'))->toBe('2027-02-17 21:00')
            ->and($start('ae'))->toBe('2027-02-17 20:00');
    });

    it('fills each of the 11 parts with artwork and words, and empties a part left out', function () {
        themesSuper();
        $parts = [
            ['part' => 'ANNOUNCEMENT', 'textAr' => 'رمضان كريم', 'textEn' => 'Ramadan Kareem', 'link' => '/categories/kitchens', 'images' => ['DESKTOP:any' => Cx::image(), 'PHONE:any' => Cx::image()]],
            ['part' => 'HERO', 'images' => ['DESKTOP:ar' => Cx::image(), 'DESKTOP:en' => Cx::image()]],
            ['part' => 'PROMISES', 'extras' => [['ar' => 'توصيل', 'en' => 'Delivery']], 'images' => ['BAND:any' => Cx::image()]],
            ['part' => 'POINTS', 'textAr' => 'نقاطك', 'textEn' => 'Your points', 'images' => ['BAND:any' => Cx::image()]],
            ['part' => 'LOGOS', 'images' => ['BAND:any' => Cx::image()]],
            ['part' => 'FLAG', 'images' => ['FLAG:any' => themesPng(), 'RIBBON:any' => themesPng()]],
            ['part' => 'BADGE', 'textAr' => 'عرض', 'textEn' => 'Offer'],
            ['part' => 'LISTING', 'images' => ['DESKTOP:ar' => Cx::image(), 'DESKTOP:en' => Cx::image()]],
            ['part' => 'CART', 'textAr' => 'شحن مجاني', 'textEn' => 'Free shipping'],
            ['part' => 'FOOTER', 'images' => ['BAND:any' => Cx::image()]],
            ['part' => 'BACKDROP', 'images' => ['START:any' => themesPng(), 'END:any' => themesPng()]],
        ];
        $theme = themesDraft(parts: $parts);

        expect(array_map(static fn ($part): string => $part->part->value, app(Themes::class)->partsOf($theme)))->toBe(array_map(static fn (ThemePartKind $kind): string => $kind->value, ThemePartKind::cases()));

        app(SaveThemeHandler::class)->handle(new SaveTheme($theme, themesVersion($theme), 'رمضان', 'Ramadan', '2027-02-18 00:00', '2027-03-19 00:00', [Fx::storeId('sa')], [$parts[6]]));

        expect(app(Themes::class)->partsOf($theme))->toHaveCount(1)
            ->and(DB::table('content.theme_part_images')->where('theme_id', $theme)->count())->toBe(0);
    });

    it('refuses what a part does not take: a slot of another part, a language on one-language artwork, a JPEG where it must be transparent, a colour', function () {
        themesSuper();
        $refused = static function (array $part): string {
            try {
                themesDraft(parts: [$part]);
            } catch (InvalidContentInput $refusal) {
                return "{$refusal->attribute}:{$refusal->rule}";
            }

            return 'saved';
        };

        expect($refused(['part' => 'FOOTER', 'images' => ['DESKTOP:any' => Cx::image()]]))->toBe('part_footer:choice')
            ->and($refused(['part' => 'FOOTER', 'images' => ['BAND:ar' => Cx::image()]]))->toBe('part_footer:choice')
            ->and($refused(['part' => 'FLAG', 'images' => ['FLAG:any' => Cx::image()]]))->toBe('part_flag:image_file')
            ->and($refused(['part' => 'BADGE', 'textAr' => str_repeat('ع', 25)]))->toBe('part_badge_ar:too_long')
            ->and($refused(['part' => 'COLOUR']))->toBe('parts:choice')
            ->and($refused(['part' => 'BADGE', 'textAr' => 'عرض', 'colour' => '#ff0000']))->toBe('saved');
    });
});

describe('scheduling and the clash (§1.8)', function () {
    it('needs English beside every Arabic, naming the part, and every image\'s sizes ready', function () {
        themesSuper();
        $noEnglish = themesDraft(parts: [['part' => 'CART', 'textAr' => 'شحن مجاني']]);
        $hero = themesDraft(parts: [['part' => 'HERO', 'images' => ['DESKTOP:ar' => Cx::image()]]]);
        $pending = themesDraft(parts: [['part' => 'FOOTER', 'images' => ['BAND:any' => Cx::image('PENDING')]]]);

        $missing = themesThrown(fn () => themesSchedule($noEnglish));
        $half = themesThrown(fn () => themesSchedule($hero));
        $notReady = themesThrown(fn () => themesSchedule($pending));

        expect($missing)->toBeInstanceOf(MissingEnglish::class)
            ->and($missing instanceof MissingEnglish ? $missing->attribute : null)->toBe('part_cart')
            ->and($half instanceof InvalidContentInput ? $half->attribute : null)->toBe('part_hero_desktop_en')
            ->and($notReady instanceof ImageNotReady ? $notReady->image : null)->toBe('part_footer');
    });

    it('refuses a second theme overlapping in one store, naming the theme, the store and its dates; a draft never clashes', function () {
        themesSuper();
        $ramadan = themesDraft(['sa', 'ae']);
        themesSchedule($ramadan);
        $overlapping = themesDraft(['ae'], '2027-03-01 00:00', '2027-04-01 00:00', nameEn: 'Eid');
        $elsewhere = themesDraft(['eg'], '2027-03-01 00:00', '2027-04-01 00:00', nameEn: 'Eid Egypt');
        $after = themesDraft(['sa', 'ae'], '2027-03-19 00:00', '2027-04-01 00:00', nameEn: 'After');

        app()->setLocale('en');
        $clash = themesThrown(fn () => themesSchedule($overlapping));
        expect($clash instanceof ThemeClash ? [$clash->theme, $clash->store, $clash->starts, $clash->ends] : $clash::class)->toBe(['Ramadan', 'United Arab Emirates', '2027-02-18 00:00', '2027-03-19 00:00']);

        themesSchedule($elsewhere);
        // Touching ends: Ramadan's end is After's start, store by store.
        themesSchedule($after);

        expect(DB::table('content.themes')->where('is_scheduled', true)->count())->toBe(3)
            ->and(DB::table('content.themes')->where('id', $overlapping)->value('is_scheduled'))->toBeFalse();
    });

    it('holds a scheduled theme to the clash when it is changed, live included, and keeps an ended one as it is', function () {
        themesSuper();
        $first = themesDraft(['sa'], '2026-11-10 00:00', '2026-11-20 00:00');
        themesSchedule($first);
        $second = themesDraft(['sa'], '2026-11-25 00:00', '2026-11-30 00:00', nameEn: 'Second');
        themesSchedule($second);

        expect(fn () => app(SaveThemeHandler::class)->handle(new SaveTheme($second, themesVersion($second), 'ثاني', 'Second', '2026-11-15 00:00', '2026-11-30 00:00', [Fx::storeId('sa')])))->toThrow(ThemeClash::class);

        // Live, and edited in full: it shows at the next page.
        app(SaveThemeHandler::class)->handle(new SaveTheme($first, themesVersion($first), 'أول', 'First', '2026-11-10 00:00', '2026-11-22 00:00', [Fx::storeId('sa')], [['part' => 'BADGE', 'textAr' => 'عرض', 'textEn' => 'Offer']]));
        expect(DB::table('content.themes')->where('id', $first)->value('name_en'))->toBe('First');

        themesClock('2026-11-23 00:00:00+00');
        expect(fn () => app(SaveThemeHandler::class)->handle(new SaveTheme($first, themesVersion($first), 'أول', 'First', '2026-11-10 00:00', '2026-11-24 00:00', [Fx::storeId('sa')])))->toThrow(InvalidContentInput::class);
    });
});

describe('its life (§4.1)', function () {
    it('goes back to draft while it has started nowhere, ends now when live, and is deleted only as an unlinked draft', function () {
        themesSuper();
        $theme = themesDraft(['sa', 'ae'], '2026-11-12 00:00', '2026-11-20 00:00');
        themesSchedule($theme);
        app(ThemeBackToDraftHandler::class)->handle(new ThemeBackToDraft($theme, themesVersion($theme)));

        expect(DB::table('content.themes')->where('id', $theme)->value('is_scheduled'))->toBeFalse();

        themesSchedule($theme);
        // The UAE's midnight came first: live there, so not back to draft.
        themesClock('2026-11-11 20:30:00+00');
        expect(fn () => app(ThemeBackToDraftHandler::class)->handle(new ThemeBackToDraft($theme, themesVersion($theme))))->toThrow(InvalidContentInput::class);

        app(EndThemeHandler::class)->handle(new EndTheme($theme, themesVersion($theme)));
        $ends = DB::table('content.theme_stores')->where('theme_id', $theme)->pluck('ends_at')->map(static fn ($at): string => CarbonImmutable::parse((string) $at)->utc()->format('Y-m-d H:i'))->all();

        expect($ends)->toBe(['2026-11-11 20:30', '2026-11-11 20:30'])
            ->and(fn () => app(DeleteThemeHandler::class)->handle(new DeleteTheme($theme, themesVersion($theme))))->toThrow(NotDeletable::class);

        $draft = themesDraft();
        Cx::editor([ContentPermissions::CAMPAIGN_MANAGE], ['sa']);
        app(SaveCampaignHandler::class)->handle(new SaveCampaign(null, 0, 'عروض', 'Offers', '2027-02-18 00:00', '2027-03-19 00:00', [Fx::storeId('sa')], $draft));
        themesSuper();

        expect(fn () => app(DeleteThemeHandler::class)->handle(new DeleteTheme($draft, themesVersion($draft))))->toThrow(NotDeletable::class);

        $unlinked = themesDraft();
        app(DeleteThemeHandler::class)->handle(new DeleteTheme($unlinked, themesVersion($unlinked)));
        expect(DB::table('content.themes')->where('id', $unlinked)->count())->toBe(0);
    });

    it('takes its campaign\'s dates store by store with Use Campaign\'s Dates, a Super Admin\'s alone (Q6 (a))', function () {
        themesSuper();
        $theme = themesDraft(['sa', 'ae']);
        Cx::editor([ContentPermissions::CAMPAIGN_MANAGE], ['sa', 'ae']);
        $campaign = app(SaveCampaignHandler::class)->handle(new SaveCampaign(null, 0, 'عروض', 'Offers', '2027-02-20 00:00', '2027-03-21 00:00', [Fx::storeId('sa')], $theme));

        expect(fn () => app(UseCampaignDatesHandler::class)->handle(new UseCampaignDates($campaign, themesVersion($theme))))->toThrow(Unauthorized::class);

        themesSuper();
        app(UseCampaignDatesHandler::class)->handle(new UseCampaignDates($campaign, themesVersion($theme)));

        expect(DB::table('content.themes')->where('id', $theme)->value('starts_at_local'))->toStartWith('2027-02-20 00:00')
            ->and(CarbonImmutable::parse((string) DB::table('content.theme_stores')->where('theme_id', $theme)->where('store_id', Fx::storeId('ae'))->value('starts_at'))->utc()->format('Y-m-d H:i'))->toBe('2027-02-19 20:00')
            ->and(Fx::audits('content.theme.dates_from_campaign', $theme))->toBe(1);
    });

    it('lets a campaign link only a theme that has not ended', function () {
        themesSuper();
        $over = themesDraft(['sa'], '2026-11-01 00:00', '2026-11-05 00:00');
        DB::table('content.themes')->where('id', $over)->update(['is_scheduled' => true]);
        Cx::editor([ContentPermissions::CAMPAIGN_MANAGE], ['sa']);

        expect(fn () => app(SaveCampaignHandler::class)->handle(new SaveCampaign(null, 0, 'عروض', 'Offers', '2027-02-20 00:00', '2027-03-21 00:00', [Fx::storeId('sa')], $over)))->toThrow(InvalidContentInput::class);
    });

    it('blocks the delete of its artwork while scheduled, and lets a draft\'s go', function () {
        themesSuper();
        $image = Cx::image();
        $scheduled = themesDraft(parts: [['part' => 'FOOTER', 'images' => ['BAND:any' => $image]]]);
        $draft = themesDraft(['sa'], '2028-01-01 00:00', '2028-02-01 00:00', [['part' => 'FOOTER', 'images' => ['BAND:any' => $image]]], 'Later');
        themesSchedule($scheduled);
        $uses = app(ContentMediaUsage::class)->usesOf($image);

        expect(array_map(static fn ($use): array => [$use->subjectId, $use->blocksDelete], $uses))->toEqualCanonicalizing([[$scheduled, true], [$draft, false]]);
    });
});

describe('the tables hold the rules, a NULL included (lessons 35, 162)', function () {
    it('refuses a scheduled theme with no English name, a store\'s end before its start, a part of no kind, a slot of none', function () {
        themesSuper();
        $theme = themesDraft(nameEn: null, parts: [['part' => 'FOOTER', 'images' => ['BAND:any' => Cx::image()]]]);
        $refused = static fn (callable $write) => expect(fn () => DB::transaction($write))->toThrow(QueryException::class);

        $refused(fn () => DB::table('content.themes')->where('id', $theme)->update(['is_scheduled' => true]));
        $refused(fn () => DB::table('content.themes')->where('id', $theme)->update(['slug' => 'base']));
        $refused(fn () => DB::table('content.theme_stores')->where('theme_id', $theme)->update(['ends_at' => '2020-01-01 00:00:00+00']));
        $refused(fn () => DB::table('content.theme_parts')->where('theme_id', $theme)->update(['part' => 'COLOUR']));
        $refused(fn () => DB::table('content.theme_part_images')->where('theme_id', $theme)->update(['slot' => 'COLOUR']));
    });
});

describe('Preview Theme (W5b)', function () {
    it('gives the presser\'s browser alone a signed cookie for two hours, and opens the theme\'s store', function () {
        $super = themesSuper();
        $theme = themesDraft(['sa']);
        $cookie = app(ThemePreviewCookie::class);
        $issued = $cookie->issue($theme, $super);
        $request = static fn (string $value): Request => Request::create('/sa/ar', cookies: [ThemePreviewCookie::NAME => $value]);

        expect($cookie->themeFor($request((string) $issued->getValue()), $super))->toBe($theme)
            ->and($cookie->themeFor($request((string) $issued->getValue()), Fx::staff()))->toBeNull()
            ->and($cookie->themeFor($request($issued->getValue().'x'), $super))->toBeNull()
            ->and($cookie->themeFor($request('forged'), $super))->toBeNull()
            ->and($issued->isHttpOnly())->toBeTrue();

        themesClock('2026-11-10 12:00:01+00');
        expect($cookie->themeFor($request((string) $issued->getValue()), $super))->toBeNull();
    });
});
