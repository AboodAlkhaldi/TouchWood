<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Database\Seeders\PlatformSeeder;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Content\Application\Command\FillHomeSection\FillHomeSection;
use Modules\Content\Application\Command\FillHomeSection\FillHomeSectionHandler;
use Modules\Content\Application\Command\OrderBanners\OrderBanners;
use Modules\Content\Application\Command\OrderBanners\OrderBannersHandler;
use Modules\Content\Application\Command\PublishPost\PublishPost;
use Modules\Content\Application\Command\PublishPost\PublishPostHandler;
use Modules\Content\Application\Command\PublishProject\PublishProject;
use Modules\Content\Application\Command\PublishProject\PublishProjectHandler;
use Modules\Content\Application\Command\SaveBar\SaveBar;
use Modules\Content\Application\Command\SaveBar\SaveBarHandler;
use Modules\Content\Application\Command\SaveFooter\SaveFooter;
use Modules\Content\Application\Command\SaveFooter\SaveFooterHandler;
use Modules\Content\Application\Command\SavePost\SavePost;
use Modules\Content\Application\Command\SavePost\SavePostHandler;
use Modules\Content\Application\Command\SaveProject\SaveProject;
use Modules\Content\Application\Command\SaveProject\SaveProjectHandler;
use Modules\Content\Application\Command\ScheduleBanner\ScheduleBanner;
use Modules\Content\Application\Command\ScheduleBanner\ScheduleBannerHandler;
use Modules\Content\Application\Command\SchedulePopup\SchedulePopup;
use Modules\Content\Application\Command\SchedulePopup\SchedulePopupHandler;
use Modules\Content\Application\Command\ShowHomeSection\ShowHomeSection;
use Modules\Content\Application\Command\ShowHomeSection\ShowHomeSectionHandler;
use Modules\Content\Application\ContentPermissions;
use Modules\Content\Application\Documents\DocumentInput;
use Modules\Content\Application\Query\Shop\ContentFrame;
use Modules\Content\Application\Query\Shop\ContentHome;
use Modules\Content\Infrastructure\Cache\VersionedContentCache;
use Modules\Platform\Public\Events\MediaVariantsReady;
use Tests\Modules\Access\Support\AccessFixtures as Fx;
use Tests\Modules\Content\Support\ContentFixtures as Cx;

use function Pest\Laravel\seed;

/*
| What the shop reads of Content (content.md §1.4, §1.5): the frame and the home page, **one cached read each per
| store and language**, filtered by the clock on every read - so a bar, a pop-up or a banner starts and ends on the
| second through a warm cache (lesson 41: each read warm before the moment it tests) - invalidated by every change
| and by a file's sizes becoming ready. The queries a warm read costs are counted and recorded here.
*/

uses(RefreshDatabase::class);

beforeEach(function () {
    seed(PlatformSeeder::class);
    // A random session sweep must not count as one of the read's queries (lesson 182).
    config(['session.lottery' => [0, 100]]);
});

/** @return array<string, mixed> */
function shopFrame(string $locale = 'ar'): array
{
    return app(ContentFrame::class)->for(Cx::store(), $locale);
}

/** @return array<string, mixed> */
function shopHome(string $locale = 'ar'): array
{
    return app(ContentHome::class)->for(Cx::store(), $locale);
}

/**
 * The queries a piece of work makes, by SQL.
 *
 * @return list<string>
 */
function shopQueries(callable $work): array
{
    /** @var ArrayObject<int, string> $queries */
    $queries = new ArrayObject;
    DB::listen(function (QueryExecuted $query) use ($queries): void {
        $queries[] = $query->sql;
    });
    $work();

    return array_values($queries->getArrayCopy());
}

/** @param  array<string, mixed>  $settings */
function shopShow(string $type, array $settings): string
{
    $section = DB::table('content.home_sections')->where('store_id', Fx::storeId('sa'))->where('type', $type)->first() ?? throw new LogicException('No section.');
    app(FillHomeSectionHandler::class)->handle(new FillHomeSection($section->id, (int) $section->version, $settings));
    app(ShowHomeSectionHandler::class)->handle(new ShowHomeSection($section->id, true));

    return $section->id;
}

describe('§1.5 the frame\'s read', function () {
    it('shows the bar from its start to its end by the clock, through a warm cache, and nothing while it is off', function () {
        Cx::editor([ContentPermissions::BAR_MANAGE]);
        app(SaveBarHandler::class)->handle(new SaveBar(Fx::storeId('sa'), 0, 'شحن مجاني', 'Free delivery', null, true, Cx::typed(5), Cx::typed(10)));

        // Both languages read before the start, so both are warm when it comes.
        expect(shopFrame()['bar'])->toBeNull()
            ->and(shopFrame('en')['bar'])->toBeNull();

        Cx::clockOn(6);
        $store = Cx::store();
        $warm = shopQueries(function () use ($store): void {
            expect(app(ContentFrame::class)->for($store, 'ar')['bar'])->toBe(['text' => 'شحن مجاني', 'link' => null])
                ->and(app(ContentFrame::class)->for($store, 'en')['bar'])->toBe(['text' => 'Free delivery', 'link' => null]);
        });

        // Warm, the cached copy is read again - no table of Content's is.
        expect($warm)->toHaveCount(4)
            ->and(array_filter($warm, static fn (string $sql): bool => str_contains($sql, 'content.')))->toBe([]);

        Cx::clockOn(5);
        expect(shopFrame()['bar'])->toBeNull();

        app(SaveBarHandler::class)->handle(new SaveBar(Fx::storeId('sa'), 1, 'شحن مجاني', 'Free delivery', null, false));
        expect(shopFrame()['bar'])->toBeNull();
    });

    it('costs two queries warm, both the cache\'s: its version and its copy', function () {
        shopFrame();
        // The store is the page's own read already (Platform's directory), not the frame's.
        $store = Cx::store();

        $warm = shopQueries(fn () => app(ContentFrame::class)->for($store, 'ar'));

        expect($warm)->toHaveCount(2)
            ->and(array_filter($warm, static fn (string $sql): bool => ! str_contains($sql, '"cache"')))->toBe([]);
    });

    it('shows a change at once: the change replaces the cached copy in its own transaction', function () {
        Cx::editor([ContentPermissions::HOMEPAGE_MANAGE]);
        $footer = static fn (string $about, int $version) => app(SaveFooterHandler::class)->handle(new SaveFooter(Fx::storeId('sa'), $version, $about, 'About us', null, null, null, null, null, null, []));
        $footer('عنا', 0);

        expect(shopFrame()['footer']['about'])->toBe('عنا');

        $footer('من نحن', 1);
        expect(shopFrame()['footer']['about'])->toBe('من نحن')
            ->and(shopFrame('en')['footer']['about'])->toBe('About us');
    });

    it('shows one pop-up at a time, the first live by its start, and the next once the first has ended', function () {
        Cx::editor([ContentPermissions::POPUP_MANAGE]);
        $first = Cx::popup(['name' => 'First', 'endsAt' => Cx::typed(60)]);
        app(SchedulePopupHandler::class)->handle(new SchedulePopup($first, 1));
        $next = Cx::popup(['name' => 'Next', 'startsAt' => Cx::typed(60), 'titleAr' => 'التالي', 'titleEn' => 'Next']);
        app(SchedulePopupHandler::class)->handle(new SchedulePopup($next, 1));

        expect(shopFrame()['popup']['id'] ?? null)->toBe($first)
            ->and(shopFrame('en')['popup']['delaySeconds'] ?? null)->toBe(8);

        Cx::clockOn(61);
        expect(shopFrame('en')['popup']['title'] ?? null)->toBe('Next');
    });
});

describe('§1.4 the home page\'s read', function () {
    it('draws the shown sections in their order, each in the language asked, and nothing hidden', function () {
        Cx::editor([ContentPermissions::HOMEPAGE_MANAGE]);
        shopShow('POINTS', ['heading' => ['ar' => 'اكسب نقاطًا', 'en' => 'Earn points']]);
        shopShow('HERO', ['title' => ['ar' => 'مطابخ تدوم', 'en' => 'Kitchens that last']]);

        $home = shopHome('en');

        expect(array_column($home['sections'], 'type'))->toBe(['HERO', 'POINTS'])
            ->and($home['sections'][0]['words']['title'])->toBe('Kitchens that last')
            ->and(shopHome()['sections'][1]['words']['heading'])->toBe('اكسب نقاطًا');
    });

    it('shows the first live banners in order, 1 behind the hero and 3 on the phone, starting and ending by the clock', function () {
        Cx::editor([ContentPermissions::BANNER_MANAGE]);
        $phones = [];

        foreach (range(1, 4) as $n) {
            $phones[] = $banner = Cx::banner(['name' => "Phone {$n}", 'endsAt' => Cx::typed(120)]);
            app(ScheduleBannerHandler::class)->handle(new ScheduleBanner($banner, 1));
        }

        $hero = Cx::banner(['placement' => 'HOME_HERO', 'images' => ['DESKTOP:any' => Cx::image()], 'startsAt' => Cx::typed(30)]);
        app(ScheduleBannerHandler::class)->handle(new ScheduleBanner($hero, 1));

        expect(array_column(shopHome()['phoneBanners'], 'id'))->toBe(array_slice($phones, 0, 3))
            ->and(shopHome()['heroBanner'])->toBeNull();

        app(OrderBannersHandler::class)->handle(new OrderBanners(Fx::storeId('sa'), 'HOME_PHONE', array_reverse($phones)));
        expect(array_column(shopHome()['phoneBanners'], 'id'))->toBe(array_slice(array_reverse($phones), 0, 3));

        // Warm from here: the hero starts, then every banner ends, with no change to replace the copy.
        Cx::clockOn(31);
        expect(shopHome()['heroBanner']['id'] ?? null)->toBe($hero)
            ->and(shopHome()['heroBanner']['image'] ?? [])->not->toBe([]);

        Cx::clockOn(90);
        expect(shopHome()['phoneBanners'])->toBe([]);
    });

    it('hides the projects row until a project is published, then shows the newest and the two newest posts', function () {
        Cx::editor([ContentPermissions::HOMEPAGE_MANAGE, ContentPermissions::PROJECT_MANAGE, ContentPermissions::BLOG_MANAGE]);
        shopShow('PROJECTS', ['heading' => ['ar' => 'مشاريعنا', 'en' => 'Our projects']]);

        expect(array_column(shopHome()['sections'], 'type'))->toBe([]);

        $input = static fn (string $ar, string $en, array $with = []): DocumentInput => new DocumentInput(...['id' => null, 'storeId' => Fx::storeId('sa'), 'version' => 0, 'titleAr' => $ar, 'titleEn' => $en, 'bodyAr' => 'نص', 'bodyEn' => 'Text', ...$with]);
        $project = app(SaveProjectHandler::class)->handle(new SaveProject($input('مطبخ الرياض', 'A Riyadh kitchen', ['photos' => [Cx::image()]])));
        app(PublishProjectHandler::class)->handle(new PublishProject($project, 1));

        foreach (['أول' => 'First', 'ثان' => 'Second', 'ثالث' => 'Third'] as $ar => $en) {
            $post = app(SavePostHandler::class)->handle(new SavePost($input($ar, $en, ['summaryAr' => 'ملخص', 'summaryEn' => 'Summary', 'coverAltAr' => 'غلاف', 'coverAltEn' => 'Cover', 'coverMediaId' => Cx::image()])));
            app(PublishPostHandler::class)->handle(new PublishPost($post, 1));
            Cx::clockOn(1);
        }

        $section = shopHome('en')['sections'][0];

        expect($section['type'])->toBe('PROJECTS')
            ->and($section['project']['title'])->toBe('A Riyadh kitchen')
            ->and(array_column($section['guides'], 'title'))->toBe(['Third', 'Second']);
    });

    it('replaces its copy when a file it shows has its sizes made, and only then', function () {
        Cx::editor([ContentPermissions::BANNER_MANAGE]);
        $image = Cx::image();
        $banner = Cx::banner(['images' => ['PHONE:any' => $image]]);
        app(ScheduleBannerHandler::class)->handle(new ScheduleBanner($banner, 1));
        expect(shopHome()['phoneBanners'])->toHaveCount(1);

        // The file's sizes fail later, with no event: the copy kept is still drawn.
        DB::table('platform.media')->where('id', $image)->update(['variants_status' => 'FAILED', 'variants_generated_at' => null]);
        $version = app('cache')->get(VersionedContentCache::NAME.':version');
        event(new MediaVariantsReady((string) Str::uuid(), strtolower((string) Str::ulid()), CarbonImmutable::now()));

        expect(shopHome()['phoneBanners'])->toHaveCount(1)
            ->and(app('cache')->get(VersionedContentCache::NAME.':version'))->toBe($version);

        event(new MediaVariantsReady((string) Str::uuid(), $image, CarbonImmutable::now()));

        // One that fails later draws as if it were not there.
        expect(app('cache')->get(VersionedContentCache::NAME.':version'))->not->toBe($version)
            ->and(shopHome()['phoneBanners'])->toBe([]);
    });

    it('costs two queries warm, both the cache\'s', function () {
        Cx::editor([ContentPermissions::HOMEPAGE_MANAGE]);
        shopShow('HERO', ['title' => ['ar' => 'مطابخ', 'en' => 'Kitchens']]);
        shopHome();
        $store = Cx::store();

        $warm = shopQueries(fn () => app(ContentHome::class)->for($store, 'ar'));

        expect($warm)->toHaveCount(2)
            ->and(array_filter($warm, static fn (string $sql): bool => ! str_contains($sql, '"cache"')))->toBe([]);
    });
});
