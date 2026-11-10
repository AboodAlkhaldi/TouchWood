<?php

declare(strict_types=1);

use Database\Seeders\PlatformSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Modules\Content\Application\Command\AddHomeSection\AddHomeSection;
use Modules\Content\Application\Command\AddHomeSection\AddHomeSectionHandler;
use Modules\Content\Application\Command\FillHomeSection\FillHomeSection;
use Modules\Content\Application\Command\FillHomeSection\FillHomeSectionHandler;
use Modules\Content\Application\Command\OrderHomeSections\OrderHomeSections;
use Modules\Content\Application\Command\OrderHomeSections\OrderHomeSectionsHandler;
use Modules\Content\Application\Command\RemoveHomeSection\RemoveHomeSection;
use Modules\Content\Application\Command\RemoveHomeSection\RemoveHomeSectionHandler;
use Modules\Content\Application\Command\SaveBar\SaveBar;
use Modules\Content\Application\Command\SaveBar\SaveBarHandler;
use Modules\Content\Application\Command\SaveFooter\SaveFooter;
use Modules\Content\Application\Command\SaveFooter\SaveFooterHandler;
use Modules\Content\Application\Command\ShowHomeSection\ShowHomeSection;
use Modules\Content\Application\Command\ShowHomeSection\ShowHomeSectionHandler;
use Modules\Content\Application\ContentPermissions;
use Modules\Content\Application\Query\HomePage\HomePageHandler;
use Modules\Content\Domain\Exception\ContentNotFound;
use Modules\Content\Domain\Exception\ImageNotReady;
use Modules\Content\Domain\Exception\InvalidContentInput;
use Modules\Content\Domain\Exception\MissingEnglish;
use Modules\Content\Domain\Exception\StaleEdit;
use Tests\Modules\Access\Support\AccessFixtures as Fx;
use Tests\Modules\Content\Support\ContentFixtures as Cx;

use function Pest\Laravel\seed;

/*
| content.md §1.4 (the home page) and §1.5 (the frame): fixed section types added, removed, ordered, shown and
| filled per store; the announcement bar and the footer. What the shop reads of them is ShopReadsTest.
*/

uses(RefreshDatabase::class);

beforeEach(function () {
    seed(PlatformSeeder::class);
});

function homeSection(string $type, string $code = 'sa'): stdClass
{
    return DB::table('content.home_sections')->where('store_id', Fx::storeId($code))->where('type', $type)->first() ?? throw new LogicException("No {$type}.");
}

function homeRefusal(callable $work): string
{
    try {
        $work();
    } catch (InvalidContentInput $refused) {
        return "{$refused->attribute}:{$refused->rule}";
    } catch (MissingEnglish $missing) {
        return "{$missing->attribute}:english";
    }

    throw new LogicException('Not refused.');
}

/**
 * @param  array<string, mixed>  $settings
 * @param  list<array{mediaId: string, nameAr: string|null, nameEn: string|null, link: string|null}>  $logos
 */
function homeFill(string $sectionId, array $settings, array $logos = []): void
{
    $version = (int) DB::table('content.home_sections')->where('id', $sectionId)->value('version');
    app(FillHomeSectionHandler::class)->handle(new FillHomeSection($sectionId, $version, $settings, $logos));
}

/**
 * Saves the store's footer with these columns, as its form would.
 *
 * @param  array<mixed>  $columns
 */
function homeFooter(array $columns, ?string $phone, ?string $email, int $version): void
{
    /** @var list<array{titleAr: string|null, titleEn: string|null, links: list<array{textAr: string|null, textEn: string|null, link: string|null}>}> $columns */
    app(SaveFooterHandler::class)->handle(new SaveFooter(Fx::storeId('sa'), $version, 'متجر', 'A store', $phone, $email, null, null, 'الحقوق محفوظة', 'All rights reserved', $columns));
}

/** @return list<array{mediaId: string, nameAr: string, nameEn: string, link: null}> */
function homeLogos(int $count, string $sizes = 'READY'): array
{
    return array_map(static fn (int $n): array => ['mediaId' => Cx::image($sizes), 'nameAr' => "عميل {$n}", 'nameEn' => "Client {$n}", 'link' => null], range(1, $count));
}

describe('§1.4 the home page', function () {
    it('starts a new store with all eight sections, hidden, in the starting order', function () {
        foreach (['sa', 'eg', 'ae'] as $code) {
            expect(DB::table('content.home_sections')->where('store_id', Fx::storeId($code))->orderBy('position')->pluck('type')->all())
                ->toBe(['HERO', 'PROMISES', 'CATEGORIES', 'BEST_SELLERS', 'PROJECTS', 'AUDIENCE', 'POINTS', 'CLIENT_LOGOS'])
                ->and(DB::table('content.home_sections')->where('store_id', Fx::storeId($code))->where('is_visible', true)->count())->toBe(0);
        }
    });

    it('keeps each type at most once: Add Section brings back only a type removed, at the end, hidden', function () {
        Cx::editor([ContentPermissions::HOMEPAGE_MANAGE]);
        $points = homeSection('POINTS');

        expect(homeRefusal(fn () => app(AddHomeSectionHandler::class)->handle(new AddHomeSection(Fx::storeId('sa'), 'POINTS'))))->toBe('type:duplicate')
            ->and(homeRefusal(fn () => app(AddHomeSectionHandler::class)->handle(new AddHomeSection(Fx::storeId('sa'), 'BUNDLES'))))->toBe('type:choice');

        app(RemoveHomeSectionHandler::class)->handle(new RemoveHomeSection($points->id));
        expect(app(HomePageHandler::class)->sections(Fx::storeId('sa'))['addable'])->toBe(['POINTS']);

        $back = app(AddHomeSectionHandler::class)->handle(new AddHomeSection(Fx::storeId('sa'), 'POINTS'));

        expect(DB::table('content.home_sections')->where('store_id', Fx::storeId('sa'))->orderByDesc('position')->value('id'))->toBe($back)
            ->and(homeSection('POINTS')->is_visible)->toBeFalse()
            ->and(DB::table('content.home_sections')->where('store_id', Fx::storeId('sa'))->orderBy('position')->pluck('position')->all())->toBe(range(0, 7))
            ->and(Fx::audits('content.home_section.added', $back))->toBe(1);
    });

    it('orders the sections as dragged, only when the order names every section once', function () {
        Cx::editor([ContentPermissions::HOMEPAGE_MANAGE]);
        $ids = array_values(array_map(strval(...), DB::table('content.home_sections')->where('store_id', Fx::storeId('sa'))->orderBy('position')->pluck('id')->all()));
        $reversed = array_reverse($ids);

        expect(homeRefusal(fn () => app(OrderHomeSectionsHandler::class)->handle(new OrderHomeSections(Fx::storeId('sa'), array_slice($reversed, 1)))))->toBe('sections:order');

        app(OrderHomeSectionsHandler::class)->handle(new OrderHomeSections(Fx::storeId('sa'), $reversed));
        expect(DB::table('content.home_sections')->where('store_id', Fx::storeId('sa'))->orderBy('position')->pluck('id')->all())->toBe($reversed);
    });

    it('saves a section\'s words with Arabic, and shows it only with English beside every Arabic: "Add the English first."', function () {
        Cx::editor([ContentPermissions::HOMEPAGE_MANAGE]);
        $hero = homeSection('HERO');
        homeFill($hero->id, ['title' => ['ar' => 'مطابخ تدوم']]);

        expect(homeRefusal(fn () => app(ShowHomeSectionHandler::class)->handle(new ShowHomeSection($hero->id, true))))->toBe('title:english')
            ->and(homeRefusal(fn () => homeFill($hero->id, ['title' => ['en' => 'Kitchens that last']])))->toBe('title_ar:required');

        homeFill($hero->id, ['title' => ['ar' => 'مطابخ تدوم', 'en' => 'Kitchens that last'], 'main' => ['text' => ['ar' => 'تسوق', 'en' => 'Shop'], 'link' => '/categories/kitchens']]);
        app(ShowHomeSectionHandler::class)->handle(new ShowHomeSection($hero->id, true));

        // Shown, its English can't be blanked.
        expect(homeSection('HERO')->is_visible)->toBeTrue()
            ->and(homeRefusal(fn () => homeFill($hero->id, ['title' => ['ar' => 'مطابخ تدوم'], 'main' => ['text' => ['ar' => 'تسوق', 'en' => 'Shop'], 'link' => '/categories/kitchens']])))->toBe('title:english');
    });

    it('holds each type to its own fields: up to 3 facts, 2 to 4 promises of the fixed icons, 4 to 12 tiles, 4 to 20 best sellers, whole buttons', function () {
        Cx::editor([ContentPermissions::HOMEPAGE_MANAGE]);
        $hero = homeSection('HERO');
        $promises = homeSection('PROMISES');
        $categories = homeSection('CATEGORIES');
        $sellers = homeSection('BEST_SELLERS');
        $promise = ['icon' => 'truck', 'title' => ['ar' => 'شحن سريع', 'en' => 'Fast delivery'], 'line' => ['ar' => 'خلال يومين', 'en' => 'In two days']];

        expect(homeRefusal(fn () => homeFill($hero->id, ['facts' => array_fill(0, 4, ['ar' => 'حقيقة', 'en' => 'Fact'])])))->toBe('facts:too_many')
            ->and(homeRefusal(fn () => homeFill($hero->id, ['main' => ['text' => ['ar' => 'تسوق', 'en' => 'Shop']]])))->toBe('main_button_link:required')
            ->and(homeRefusal(fn () => homeFill($promises->id, ['promises' => array_fill(0, 5, $promise)])))->toBe('promises:too_many')
            ->and(homeRefusal(fn () => homeFill($promises->id, ['promises' => [[...$promise, 'icon' => 'rocket']]])))->toBe('promise_icon:choice')
            ->and(homeRefusal(fn () => homeFill($categories->id, ['count' => 13])))->toBe('count:range')
            ->and(homeRefusal(fn () => homeFill($sellers->id, ['count' => 3])))->toBe('count:range');

        homeFill($promises->id, ['promises' => [$promise]]);
        expect(homeRefusal(fn () => app(ShowHomeSectionHandler::class)->handle(new ShowHomeSection($promises->id, true))))->toBe('promises:too_few');

        homeFill($sellers->id, ['heading' => ['ar' => 'الأكثر مبيعًا', 'en' => 'Best sellers'], 'count' => 20]);
        homeFill($categories->id, ['heading' => ['ar' => 'الفئات', 'en' => 'Categories']]);

        expect(json_decode((string) DB::table('content.home_sections')->where('id', $sellers->id)->value('settings'), true)['count'])->toBe(20)
            ->and(json_decode((string) DB::table('content.home_sections')->where('id', $categories->id)->value('settings'), true)['count'])->toBe(8);
    });

    it('keeps the Points band to its words: the figures are read from the Points settings, never typed', function () {
        Cx::editor([ContentPermissions::HOMEPAGE_MANAGE]);
        $points = homeSection('POINTS');
        homeFill($points->id, ['small_heading' => ['ar' => 'نقاط', 'en' => 'Points'], 'heading' => ['ar' => 'اكسب', 'en' => 'Earn'], 'earn_per_unit' => 100]);

        $kept = array_keys(json_decode((string) DB::table('content.home_sections')->where('id', $points->id)->value('settings'), true));
        // jsonb keeps keys in its own order (lesson 133).
        sort($kept);

        expect($kept)->toBe(['heading', 'line', 'small_heading']);
    });

    it('shows the logos band only with 6 to 40 logos, each ready, each named in both languages', function () {
        Cx::editor([ContentPermissions::HOMEPAGE_MANAGE]);
        $band = homeSection('CLIENT_LOGOS');
        $heading = ['heading' => ['ar' => 'عملاؤنا', 'en' => 'Our clients']];

        expect(homeRefusal(fn () => homeFill($band->id, $heading, homeLogos(41))))->toBe('logos:too_many');

        homeFill($band->id, $heading, homeLogos(5));
        expect(homeRefusal(fn () => app(ShowHomeSectionHandler::class)->handle(new ShowHomeSection($band->id, true))))->toBe('logos:too_few');

        homeFill($band->id, $heading, [...homeLogos(5), ...homeLogos(1, 'PENDING')]);
        expect(fn () => app(ShowHomeSectionHandler::class)->handle(new ShowHomeSection($band->id, true)))->toThrow(ImageNotReady::class);

        homeFill($band->id, $heading, homeLogos(6));
        app(ShowHomeSectionHandler::class)->handle(new ShowHomeSection($band->id, true));

        expect(DB::table('content.home_section_media')->where('section_id', $band->id)->count())->toBe(6)
            ->and(homeSection('CLIENT_LOGOS')->is_visible)->toBeTrue();
    });

    it('refuses a fill over a newer version (§1.1 rule 12)', function () {
        Cx::editor([ContentPermissions::HOMEPAGE_MANAGE]);
        $hero = homeSection('HERO');
        homeFill($hero->id, ['title' => ['ar' => 'أول']]);

        expect(fn () => app(FillHomeSectionHandler::class)->handle(new FillHomeSection($hero->id, 1, ['title' => ['ar' => 'ثان']])))->toThrow(StaleEdit::class);
    });

    it('keeps each store\'s home its own', function () {
        Cx::editor([ContentPermissions::HOMEPAGE_MANAGE], ['ae']);

        expect(fn () => app(ShowHomeSectionHandler::class)->handle(new ShowHomeSection(homeSection('HERO', 'sa')->id, false)))->toThrow(ContentNotFound::class)
            ->and(app(HomePageHandler::class)->sections(Fx::storeId('ae'))['sections'])->toHaveCount(8);
    });
});

describe('§1.5 the frame', function () {
    it('keeps one announcement bar a store, one line of 120 characters, switched on only with both languages', function () {
        Cx::editor([ContentPermissions::BAR_MANAGE], ['sa', 'ae']);

        expect(homeRefusal(fn () => app(SaveBarHandler::class)->handle(new SaveBar(Fx::storeId('sa'), 0, str_repeat('ش', 121), 'Free', null, false))))->toBe('text_ar:too_long')
            ->and(homeRefusal(fn () => app(SaveBarHandler::class)->handle(new SaveBar(Fx::storeId('sa'), 0, "شحن\nمجاني", 'Free', null, false))))->toBe('text_ar:one_line')
            ->and(homeRefusal(fn () => app(SaveBarHandler::class)->handle(new SaveBar(Fx::storeId('sa'), 0, null, null, null, true))))->toBe('text_ar:required');

        app(SaveBarHandler::class)->handle(new SaveBar(Fx::storeId('sa'), 0, 'شحن مجاني', 'Free delivery', '/pages/delivery', true, Cx::typed(60), Cx::typed(120)));
        app(SaveBarHandler::class)->handle(new SaveBar(Fx::storeId('sa'), 1, 'شحن مجاني اليوم', 'Free delivery today', '/pages/delivery', true));
        app(SaveBarHandler::class)->handle(new SaveBar(Fx::storeId('ae'), 0, 'خصم', 'Sale', null, true));

        expect(DB::table('content.bars')->count())->toBe(2)
            ->and(DB::table('content.bars')->where('store_id', Fx::storeId('sa'))->value('text_en'))->toBe('Free delivery today')
            ->and(fn () => app(SaveBarHandler::class)->handle(new SaveBar(Fx::storeId('sa'), 1, 'قديم', 'Old', null, true)))->toThrow(StaleEdit::class)
            ->and(homeRefusal(fn () => app(SaveBarHandler::class)->handle(new SaveBar(Fx::storeId('sa'), 2, 'خصم', 'Sale', null, true, Cx::typed(120), Cx::typed(60)))))->toBe('ends_at:dates');
    });

    it('keeps a footer a store: up to 3 columns of up to 8 links, each line in both languages', function () {
        Cx::editor([ContentPermissions::HOMEPAGE_MANAGE]);
        $link = ['textAr' => 'الضمان', 'textEn' => 'Warranty', 'link' => '/pages/warranty'];
        $column = ['titleAr' => 'المساعدة', 'titleEn' => 'Help', 'links' => [$link]];
        $save = static fn (array $columns, ?string $phone = null, ?string $email = null, int $version = 0) => homeFooter($columns, $phone, $email, $version);

        expect(homeRefusal(fn () => $save(array_fill(0, 4, $column))))->toBe('columns:too_many')
            ->and(homeRefusal(fn () => $save([[...$column, 'links' => array_fill(0, 9, $link)]])))->toBe('column_links:too_many')
            ->and(homeRefusal(fn () => $save([[...$column, 'titleEn' => null]])))->toBe('column_title:english')
            ->and(homeRefusal(fn () => $save([[...$column, 'links' => [[...$link, 'link' => 'javascript:x']]]])))->toBe('link:link')
            ->and(homeRefusal(fn () => $save([], 'call me')))->toBe('phone:phone')
            ->and(homeRefusal(fn () => $save([], null, 'not-an-email')))->toBe('email:email');

        $save([$column, $column, $column], '+966 11 000 0000', 'help@example.com');

        expect(json_decode((string) DB::table('content.footers')->where('store_id', Fx::storeId('sa'))->value('columns'), true))->toHaveCount(3)
            ->and(app(HomePageHandler::class)->footer(Fx::storeId('sa'))['version'])->toBe(1);

        // Its contact lines reach the audit as changed only.
        $changes = json_decode((string) DB::table('platform.audit_entries')->where('action', 'content.footer.saved')->value('changes'), true);
        expect($changes['phone'])->toBe('changed')
            ->and($changes['about_ar'])->toBe([null, 'متجر']);
    });
});
