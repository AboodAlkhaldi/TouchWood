<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Database\Seeders\PlatformSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Catalog\Application\Command\DeleteCategory\DeleteCategory;
use Modules\Catalog\Application\Command\DeleteCategory\DeleteCategoryHandler;
use Modules\Catalog\Domain\Exception\CatalogItemInUse;
use Modules\Content\Application\Command\DeleteBanner\DeleteBanner;
use Modules\Content\Application\Command\DeleteBanner\DeleteBannerHandler;
use Modules\Content\Application\Command\DeletePage\DeletePage;
use Modules\Content\Application\Command\DeletePage\DeletePageHandler;
use Modules\Content\Application\Command\PublishPage\PublishPage;
use Modules\Content\Application\Command\PublishPage\PublishPageHandler;
use Modules\Content\Application\Command\SaveBanner\SaveBanner;
use Modules\Content\Application\Command\SaveBanner\SaveBannerHandler;
use Modules\Content\Application\Command\SaveBar\SaveBar;
use Modules\Content\Application\Command\SaveBar\SaveBarHandler;
use Modules\Content\Application\Command\SaveFooter\SaveFooter;
use Modules\Content\Application\Command\SaveFooter\SaveFooterHandler;
use Modules\Content\Application\Command\SavePage\SavePage;
use Modules\Content\Application\Command\SavePage\SavePageHandler;
use Modules\Content\Application\Command\SavePost\SavePost;
use Modules\Content\Application\Command\SavePost\SavePostHandler;
use Modules\Content\Application\Command\SaveSmartBar\SaveSmartBar;
use Modules\Content\Application\Command\SaveSmartBar\SaveSmartBarHandler;
use Modules\Content\Application\Command\ScheduleBanner\ScheduleBanner;
use Modules\Content\Application\Command\ScheduleBanner\ScheduleBannerHandler;
use Modules\Content\Application\ContentPermissions;
use Modules\Content\Application\Documents\DocumentInput;
use Modules\Content\Application\Query\Banners\BannersHandler;
use Modules\Content\Application\Query\Documents\DocumentsHandler;
use Modules\Content\Domain\Exception\ContentNotFound;
use Modules\Content\Domain\Exception\ImageNotReady;
use Modules\Content\Domain\Exception\InvalidContentInput;
use Modules\Content\Domain\Exception\MissingEnglish;
use Modules\Content\Domain\Exception\NotDeletable;
use Modules\Content\Domain\Exception\SlugTaken;
use Modules\Content\Domain\Exception\StaleEdit;
use Modules\Content\Domain\Exception\StoreOff;
use Modules\Content\Domain\Model\DocumentKind;
use Shared\Application\Unauthorized;
use Tests\Modules\Access\Support\AccessFixtures as Fx;
use Tests\Modules\Catalog\Support\CatalogProducts as Px;
use Tests\Modules\Content\Support\ContentFixtures as Cx;

use function Pest\Laravel\seed;

/*
| content.md §1.1, what every content item follows: one test (at least) named for each of its seventeen rules, on
| the items this PR builds (plan PR 3's "Must show"). Rules 4, 8 and 9 are the screens' and the shop's; what the
| backend holds of them is tested here, and their drawing waits for their pages (PR 7, PR 9, PR 10).
*/

uses(RefreshDatabase::class);

beforeEach(function () {
    seed(PlatformSeeder::class);
});

/** @param  array<string, mixed>  $with */
function rulesPage(array $with): DocumentInput
{
    return new DocumentInput(...['id' => null, 'storeId' => Fx::storeId('sa'), 'version' => 0, 'titleAr' => 'عن المتجر', 'titleEn' => null, ...$with]);
}

it('§1.1 rule 1: belongs to one store - another store\'s item is not found, its list refused', function () {
    Cx::editor([ContentPermissions::BANNER_MANAGE], ['sa']);
    $banner = Cx::banner();

    Cx::editor([ContentPermissions::BANNER_MANAGE], ['ae']);

    expect(fn () => app(BannersHandler::class)->banner($banner))->toThrow(ContentNotFound::class)
        ->and(fn () => app(ScheduleBannerHandler::class)->handle(new ScheduleBanner($banner, 1)))->toThrow(ContentNotFound::class)
        ->and(fn () => app(BannersHandler::class)->list(Fx::storeId('sa'), 'HOME_PHONE'))->toThrow(Unauthorized::class)
        ->and(app(BannersHandler::class)->list(Fx::storeId('ae'), 'HOME_PHONE')['rows'])->toBe([]);
});

it('§1.1 rule 2: saves with Arabic alone, publishes only with English, and refuses English blanked on what is published', function () {
    Cx::editor([ContentPermissions::PAGE_MANAGE]);
    $page = app(SavePageHandler::class)->handle(new SavePage(rulesPage(['bodyAr' => 'نص'])));

    expect(fn () => app(SavePageHandler::class)->handle(new SavePage(rulesPage(['titleAr' => null, 'titleEn' => 'About']))))->toThrow(InvalidContentInput::class)
        ->and(fn () => app(PublishPageHandler::class)->handle(new PublishPage($page, 1)))->toThrow(MissingEnglish::class);

    app(SavePageHandler::class)->handle(new SavePage(rulesPage(['id' => $page, 'version' => 1, 'bodyAr' => 'نص', 'titleEn' => 'About', 'bodyEn' => 'Text'])));
    app(PublishPageHandler::class)->handle(new PublishPage($page, 2));

    expect(fn () => app(SavePageHandler::class)->handle(new SavePage(rulesPage(['id' => $page, 'version' => 3, 'bodyAr' => 'نص']))))->toThrow(MissingEnglish::class)
        ->and(DB::table('content.page_translations')->where('page_id', $page)->where('locale', 'en')->value('title'))->toBe('About');
});

it('§1.1 rule 2: where there is no Publish step, refuses on save without both languages', function () {
    Cx::editor([ContentPermissions::HOMEPAGE_MANAGE, ContentPermissions::BAR_MANAGE]);
    $category = Px::category();

    expect(fn () => app(SaveFooterHandler::class)->handle(new SaveFooter(Fx::storeId('sa'), 0, 'متجر الأدوات', null, null, null, null, null, null, null, [])))->toThrow(MissingEnglish::class)
        ->and(fn () => app(SaveBarHandler::class)->handle(new SaveBar(Fx::storeId('sa'), 0, 'شحن مجاني', null, null, true)))->toThrow(MissingEnglish::class)
        ->and(fn () => app(SaveSmartBarHandler::class)->handle(new SaveSmartBar(null, Fx::storeId('sa'), 'CATEGORY', $category, null, 'خصم', null, null)))->toThrow(MissingEnglish::class);

    // Off, the bar keeps the Arabic typed so far.
    app(SaveBarHandler::class)->handle(new SaveBar(Fx::storeId('sa'), 0, 'شحن مجاني', null, null, false));
    expect(DB::table('content.bars')->where('store_id', Fx::storeId('sa'))->value('text_ar'))->toBe('شحن مجاني');
});

it('§1.1 rule 3: saves every digit 0-9, Arabic-Indic ones typed included, in words and in a body', function () {
    Cx::editor([ContentPermissions::PAGE_MANAGE]);
    $page = app(SavePageHandler::class)->handle(new SavePage(rulesPage(['titleAr' => 'عرض ٢٠٢٧', 'bodyAr' => 'خصم ٣٠٪ حتى ۱۵ مارس'])));

    $row = DB::table('content.page_translations')->where('page_id', $page)->where('locale', 'ar')->first();

    expect($row?->title)->toBe('عرض 2027')
        ->and($row?->slug)->toBe('عرض-2027')
        ->and((string) $row?->body)->toContain('خصم 30٪ حتى 15 مارس');
});

it('§1.1 rule 4: keeps each language\'s words apart, so each box writes in its own direction', function () {
    Cx::editor([ContentPermissions::PAGE_MANAGE]);
    $page = app(SavePageHandler::class)->handle(new SavePage(rulesPage(['bodyAr' => 'نص', 'titleEn' => 'About', 'bodyEn' => 'Text'])));

    expect(DB::table('content.page_translations')->where('page_id', $page)->orderBy('locale')->pluck('title', 'locale')->all())->toBe(['ar' => 'عن المتجر', 'en' => 'About']);
});

it('§1.1 rule 5: publishes pages at once, with no dates; keeps a banner\'s start and end; only Content\'s jobs change them', function () {
    Cx::editor([ContentPermissions::PAGE_MANAGE]);
    $page = Cx::page();
    app(PublishPageHandler::class)->handle(new PublishPage($page, 1));

    expect(DB::table('content.pages')->where('id', $page)->value('status'))->toBe('PUBLISHED')
        ->and(CarbonImmutable::parse((string) DB::table('content.pages')->where('id', $page)->value('published_at'))->equalTo(CarbonImmutable::now()->startOfSecond()))->toBeTrue()
        ->and(fn () => Cx::banner())->toThrow(Unauthorized::class);
});

it('§1.1 rule 6: reads a typed moment on each store\'s own clock, and keeps it in UTC', function () {
    Cx::editor([ContentPermissions::BANNER_MANAGE], ['sa', 'ae']);
    $sa = Cx::banner(['startsAt' => '2027-02-18 00:00']);
    $ae = Cx::banner(['startsAt' => '2027-02-18 00:00'], 'ae');

    expect(CarbonImmutable::parse((string) DB::table('content.banners')->where('id', $sa)->value('starts_at'))->utc()->format('Y-m-d H:i'))->toBe('2027-02-17 21:00')
        ->and(CarbonImmutable::parse((string) DB::table('content.banners')->where('id', $ae)->value('starts_at'))->utc()->format('Y-m-d H:i'))->toBe('2027-02-17 20:00');
});

it('§1.1 rule 7: refuses an off store to whoever may not switch stores, lets one who may prepare it, and keeps its dates', function () {
    Cx::editor([ContentPermissions::BANNER_MANAGE], ['eg']);
    $banner = Cx::banner(['endsAt' => Cx::typed(600, 'eg')], 'eg');
    app(ScheduleBannerHandler::class)->handle(new ScheduleBanner($banner, 1));
    $before = DB::table('content.banners')->where('id', $banner)->first(['starts_at', 'ends_at', 'is_scheduled']);
    $eg = Cx::switchOff('eg');

    expect(fn () => app(BannersHandler::class)->list($eg, 'HOME_PHONE'))->toThrow(StoreOff::class)
        ->and(fn () => app(BannersHandler::class)->banner($banner))->toThrow(StoreOff::class)
        ->and(DB::table('content.banners')->where('id', $banner)->first(['starts_at', 'ends_at', 'is_scheduled']))->toEqual($before);

    Fx::actAsStaff(Fx::staff(superAdmin: true));
    expect(app(BannersHandler::class)->list($eg, 'HOME_PHONE')['rows'])->toHaveCount(1);
});

it('§1.1 rule 8: keeps no colour - no Content table has a column for one', function () {
    $columns = DB::table('information_schema.columns')->where('table_schema', 'content')->pluck('column_name')->all();

    expect($columns)->not->toBeEmpty()
        ->and(array_filter($columns, static fn (string $column): bool => preg_match('/colou?r|hex|rgb/', $column) === 1))->toBe([]);
});

it('§1.1 rule 10: takes only public JPEG, PNG or WebP images of the library', function (string $visibility, string $mime) {
    Cx::editor([ContentPermissions::BANNER_MANAGE]);

    try {
        Cx::banner(['images' => ['PHONE:any' => Cx::image('READY', $visibility, $mime)]]);
    } catch (InvalidContentInput $refused) {
        expect([$refused->attribute, $refused->rule])->toBe(['phone_image', InvalidContentInput::IMAGE_FILE]);

        return;
    }

    throw new LogicException('Not refused.');
})->with([
    'a private file' => ['PRIVATE', 'image/jpeg'],
    'a PDF' => ['PUBLIC', 'application/pdf'],
    'a GIF' => ['PUBLIC', 'image/gif'],
]);

it('§1.1 rule 10: refuses Schedule while an image\'s sizes are being made, and asks for it again when they failed', function (string $sizes, bool $failed) {
    Cx::editor([ContentPermissions::BANNER_MANAGE]);
    $banner = Cx::banner(['images' => ['PHONE:any' => Cx::image($sizes)]]);

    try {
        app(ScheduleBannerHandler::class)->handle(new ScheduleBanner($banner, 1));
    } catch (ImageNotReady $refused) {
        expect($refused->image)->toBe('phone_image')
            ->and($refused->failed)->toBe($failed)
            ->and(DB::table('content.banners')->where('id', $banner)->value('is_scheduled'))->toBeFalse();

        return;
    }

    throw new LogicException('Not refused.');
})->with([
    'pending' => ['PENDING', false],
    'failed' => ['FAILED', true],
]);

it('§1.1 rule 11: deletes a draft for good, freeing its addresses, and refuses what is published or scheduled', function () {
    Cx::editor([ContentPermissions::PAGE_MANAGE, ContentPermissions::BANNER_MANAGE]);
    $draft = Cx::page(['titleAr' => 'الشحن', 'titleEn' => 'Shipping']);
    $published = Cx::page();
    app(PublishPageHandler::class)->handle(new PublishPage($published, 1));
    $banner = Cx::banner();
    app(ScheduleBannerHandler::class)->handle(new ScheduleBanner($banner, 1));

    app(DeletePageHandler::class)->handle(new DeletePage($draft));

    expect(DB::table('content.pages')->where('id', $draft)->exists())->toBeFalse()
        ->and(DB::table('content.slug_history')->where('item_id', $draft)->exists())->toBeFalse()
        ->and(Cx::page(['titleAr' => 'الشحن', 'titleEn' => 'Shipping']))->toBeString()
        ->and(fn () => app(DeletePageHandler::class)->handle(new DeletePage($published)))->toThrow(NotDeletable::class)
        ->and(fn () => app(DeleteBannerHandler::class)->handle(new DeleteBanner($banner)))->toThrow(NotDeletable::class)
        ->and(Fx::audits('content.page.deleted', $draft))->toBe(1);
});

it('§1.1 rule 12: refuses a save over a newer version, naming who and when, and writes nothing', function () {
    $first = Cx::editor([ContentPermissions::PAGE_MANAGE]);
    $page = Cx::page();
    app(SavePageHandler::class)->handle(new SavePage(rulesPage(['id' => $page, 'version' => 1, 'titleAr' => 'عنوان جديد'])));

    Cx::editor([ContentPermissions::PAGE_MANAGE]);

    try {
        app(SavePageHandler::class)->handle(new SavePage(rulesPage(['id' => $page, 'version' => 1, 'titleAr' => 'عنوان آخر'])));
    } catch (StaleEdit $stale) {
        expect($stale->at)->toBe(CarbonImmutable::now()->setTimezone('Asia/Riyadh')->format('Y-m-d H:i'))
            ->and($stale->by)->not->toBe('-')
            ->and(DB::table('content.page_translations')->where('page_id', $page)->where('locale', 'ar')->value('title'))->toBe('عنوان جديد')
            ->and(DB::table('content.pages')->where('id', $page)->value('updated_by'))->toBe($first);

        return;
    }

    throw new LogicException('Not refused.');
});

it('§1.1 rule 13: audits short fields by value, from and to, and a body as changed with its length', function () {
    Cx::editor([ContentPermissions::PAGE_MANAGE]);
    $page = Cx::page(['titleAr' => 'قديم', 'bodyAr' => 'نص قصير']);
    app(SavePageHandler::class)->handle(new SavePage(rulesPage(['id' => $page, 'version' => 1, 'titleAr' => 'جديد', 'bodyAr' => 'نص أطول من الأول'])));

    $changes = json_decode((string) DB::table('platform.audit_entries')->where('action', 'content.page.changed')->where('subject_id', $page)->value('changes'), true);

    // Compared key by key with toBe: a swapped from and to must fail (lesson 105).
    expect($changes['title_ar'])->toBe(['قديم', 'جديد'])
        ->and($changes['slug_ar'])->toBe(['قديم', 'جديد'])
        ->and($changes['body_ar_length'][0])->toBeLessThan($changes['body_ar_length'][1])
        ->and(json_encode($changes, JSON_UNESCAPED_UNICODE))->not->toContain('نص أطول');
});

it('§1.1 rule 14: names every refusal and every audited action in both languages, the same keys in each', function () {
    $root = dirname(__DIR__, 4).'/src/Modules/Content/Presentation/lang';

    foreach (['errors', 'audit'] as $file) {
        $ar = require "{$root}/ar/{$file}.php";
        $en = require "{$root}/en/{$file}.php";
        $flatten = static function (array $lines, string $prefix = '') use (&$flatten): array {
            $flat = [];

            foreach ($lines as $key => $value) {
                $flat = is_array($value) ? [...$flat, ...$flatten($value, "{$prefix}{$key}.")] : [...$flat, "{$prefix}{$key}" => (string) $value];
            }

            return $flat;
        };

        expect(array_keys($flatten($ar)))->toBe(array_keys($flatten($en)))
            ->and(array_filter($flatten($ar), static fn (string $text): bool => trim($text) === ''))->toBe([]);
    }

    foreach ((new ReflectionClass(InvalidContentInput::class))->getConstants() as $rule) {
        foreach (['ar', 'en'] as $locale) {
            expect(trans("content::errors.invalid_content_input.rules.{$rule}", [], $locale))->not->toBe("content::errors.invalid_content_input.rules.{$rule}");
        }
    }
});

it('§1.1 rule 15: makes an address per language from the title, unique per store, language and kind, every old one kept', function () {
    Cx::editor([ContentPermissions::PAGE_MANAGE, ContentPermissions::BLOG_MANAGE], ['sa', 'ae']);
    $page = Cx::page(['titleAr' => 'دليل التركيب', 'titleEn' => 'Fitting Guide']);

    expect(DB::table('content.page_translations')->where('page_id', $page)->orderBy('locale')->pluck('slug')->all())->toBe(['دليل-التركيب', 'fitting-guide'])
        // Another store, and another kind, may use the same address.
        ->and(Cx::page(['titleAr' => 'دليل التركيب', 'titleEn' => 'Fitting Guide'], 'ae'))->toBeString()
        ->and(app(SavePostHandler::class)->handle(new SavePost(rulesPage(['titleAr' => 'دليل التركيب', 'titleEn' => 'Fitting Guide']))))->toBeString()
        // Another page of the store may not, now or once the first has moved on.
        ->and(fn () => Cx::page(['titleAr' => 'صفحة أخرى', 'titleEn' => 'Other', 'slugEn' => 'fitting-guide']))->toThrow(SlugTaken::class);

    app(SavePageHandler::class)->handle(new SavePage(rulesPage(['id' => $page, 'version' => 1, 'titleAr' => 'دليل التركيب', 'titleEn' => 'Fitting Guide', 'slugEn' => 'how-to-fit'])));

    expect(DB::table('content.slug_history')->where('item_id', $page)->where('locale', 'en')->orderBy('slug')->pluck('slug')->all())->toBe(['fitting-guide', 'how-to-fit'])
        ->and(fn () => Cx::page(['titleAr' => 'صفحة ثالثة', 'titleEn' => 'Fitting Guide']))->toThrow(SlugTaken::class);

    // Its own old address it may take back.
    app(SavePageHandler::class)->handle(new SavePage(rulesPage(['id' => $page, 'version' => 2, 'titleAr' => 'دليل التركيب', 'titleEn' => 'Fitting Guide', 'slugEn' => 'fitting-guide'])));
    expect(DB::table('content.page_translations')->where('page_id', $page)->where('locale', 'en')->value('slug'))->toBe('fitting-guide');
});

it('§1.1 rule 16: refuses a link that is neither a shop page nor an https:// address, and lists where Content\'s links name a page', function () {
    Cx::editor([ContentPermissions::PAGE_MANAGE, ContentPermissions::BANNER_MANAGE, ContentPermissions::HOMEPAGE_MANAGE, ContentPermissions::BAR_MANAGE]);

    expect(fn () => Cx::banner(['link' => 'javascript:alert(1)']))->toThrow(InvalidContentInput::class);

    $page = Cx::page(['titleAr' => 'الضمان', 'titleEn' => 'Warranty']);
    $banner = Cx::banner(['link' => '/pages/warranty']);
    app(SaveBarHandler::class)->handle(new SaveBar(Fx::storeId('sa'), 0, 'الضمان', 'Warranty', '/pages/الضمان#terms', true));
    app(SaveFooterHandler::class)->handle(new SaveFooter(Fx::storeId('sa'), 0, null, null, null, null, null, null, null, null, [
        ['titleAr' => 'المساعدة', 'titleEn' => 'Help', 'links' => [['textAr' => 'الضمان', 'textEn' => 'Warranty', 'link' => '/pages/warranty']]],
    ]));
    Cx::banner(['link' => '/pages/other']);

    expect(app(DocumentsHandler::class)->linksTo(DocumentKind::Page, $page))->toBe([
        ['place' => 'banner', 'id' => $banner],
        ['place' => 'bar', 'id' => Fx::storeId('sa')],
        ['place' => 'footer', 'id' => Fx::storeId('sa')],
    ]);
});

it('§1.1 rule 17: refuses Catalog\'s delete of a category a banner or a smart bar names, naming the use', function () {
    Cx::editor([ContentPermissions::BANNER_MANAGE, ContentPermissions::BAR_MANAGE]);
    $listed = Px::category();
    $barred = Px::category();
    $banner = Cx::banner(['placement' => 'LISTING', 'categoryId' => $listed, 'images' => ['DESKTOP:any' => Cx::image(), 'PHONE:any' => Cx::image()]]);
    $bar = app(SaveSmartBarHandler::class)->handle(new SaveSmartBar(null, Fx::storeId('sa'), 'CATEGORY', $barred, null, 'خصم', 'Sale', null));

    foreach ([$listed => $banner, $barred => $bar] as $category => $use) {
        try {
            Fx::asSystem(fn () => app(DeleteCategoryHandler::class)->handle(new DeleteCategory($category)));
        } catch (CatalogItemInUse $refused) {
            expect($refused->modules)->toBe(['content'])
                ->and(DB::table('catalog.categories')->where('id', $category)->exists())->toBeTrue();

            continue;
        }

        throw new LogicException("The category {$category} used by {$use} was deleted.");
    }
});

it('refuses a listing category to any other placement, and a category that does not exist', function () {
    Cx::editor([ContentPermissions::BANNER_MANAGE]);

    expect(fn () => Cx::banner(['categoryId' => Px::category()]))->toThrow(InvalidContentInput::class)
        ->and(fn () => app(SaveBannerHandler::class)->handle(new SaveBanner(null, Fx::storeId('sa'), 0, 'LISTING', strtolower((string) Str::ulid()), 'x', null, false, 'وصف', null, null, null, [])))->toThrow(InvalidContentInput::class);
});
