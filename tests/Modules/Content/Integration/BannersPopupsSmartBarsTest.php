<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Database\Seeders\PlatformSeeder;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Content\Application\Command\BannerBackToDraft\BannerBackToDraft;
use Modules\Content\Application\Command\BannerBackToDraft\BannerBackToDraftHandler;
use Modules\Content\Application\Command\DeleteBanner\DeleteBanner;
use Modules\Content\Application\Command\DeleteBanner\DeleteBannerHandler;
use Modules\Content\Application\Command\DeletePopup\DeletePopup;
use Modules\Content\Application\Command\DeletePopup\DeletePopupHandler;
use Modules\Content\Application\Command\DeleteSmartBar\DeleteSmartBar;
use Modules\Content\Application\Command\DeleteSmartBar\DeleteSmartBarHandler;
use Modules\Content\Application\Command\EndBanner\EndBanner;
use Modules\Content\Application\Command\EndBanner\EndBannerHandler;
use Modules\Content\Application\Command\EndPopup\EndPopup;
use Modules\Content\Application\Command\EndPopup\EndPopupHandler;
use Modules\Content\Application\Command\OrderBanners\OrderBanners;
use Modules\Content\Application\Command\OrderBanners\OrderBannersHandler;
use Modules\Content\Application\Command\PopupBackToDraft\PopupBackToDraft;
use Modules\Content\Application\Command\PopupBackToDraft\PopupBackToDraftHandler;
use Modules\Content\Application\Command\SaveBanner\SaveBanner;
use Modules\Content\Application\Command\SaveBanner\SaveBannerHandler;
use Modules\Content\Application\Command\SavePopup\SavePopup;
use Modules\Content\Application\Command\SavePopup\SavePopupHandler;
use Modules\Content\Application\Command\SaveSmartBar\SaveSmartBar;
use Modules\Content\Application\Command\SaveSmartBar\SaveSmartBarHandler;
use Modules\Content\Application\Command\ScheduleBanner\ScheduleBanner;
use Modules\Content\Application\Command\ScheduleBanner\ScheduleBannerHandler;
use Modules\Content\Application\Command\SchedulePopup\SchedulePopup;
use Modules\Content\Application\Command\SchedulePopup\SchedulePopupHandler;
use Modules\Content\Application\ContentPermissions;
use Modules\Content\Application\Query\Banners\BannersHandler;
use Modules\Content\Application\Query\Bars\BarsHandler;
use Modules\Content\Application\Query\Popups\PopupsHandler;
use Modules\Content\Domain\Exception\InvalidContentInput;
use Modules\Content\Domain\Exception\MissingEnglish;
use Modules\Content\Domain\Exception\NotDeletable;
use Modules\Content\Domain\Exception\PopupClash;
use Tests\Modules\Access\Support\AccessFixtures as Fx;
use Tests\Modules\Catalog\Support\CatalogProducts as Px;
use Tests\Modules\Content\Support\ContentFixtures as Cx;

use function Pest\Laravel\seed;

/*
| content.md §1.6 (banners and their images), §1.9 (smart bars) and §1.10 (the pop-up): what each holds, how it is
| scheduled, ended, taken back to draft and deleted, and the pop-up's one-at-a-time rule under the store's lock.
| Which ones the shop draws, and when, is ShopReadsTest.
*/

uses(RefreshDatabase::class);

beforeEach(function () {
    seed(PlatformSeeder::class);
});

function bannerRefusal(callable $work): string
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

function bannerVersion(string $id): int
{
    return (int) DB::table('content.banners')->where('id', $id)->value('version');
}

function popupVersion(string $id): int
{
    return (int) DB::table('content.popups')->where('id', $id)->value('version');
}

describe('§1.6 banners', function () {
    it('takes the images its placement takes: a desktop one behind the hero, a phone one on the phone home, both above lists', function () {
        Cx::editor([ContentPermissions::BANNER_MANAGE]);

        expect(bannerRefusal(fn () => Cx::banner(['placement' => 'HOME_HERO', 'images' => ['PHONE:any' => Cx::image()]])))->toBe('images:choice')
            ->and(bannerRefusal(fn () => Cx::banner(['placement' => 'HOME_PHONE', 'images' => ['DESKTOP:any' => Cx::image()]])))->toBe('images:choice')
            ->and(bannerRefusal(fn () => Cx::banner(['placement' => 'TOP'])))->toBe('placement:choice');

        $listing = Cx::banner(['placement' => 'LISTING', 'images' => ['DESKTOP:any' => Cx::image()]]);

        expect(bannerRefusal(fn () => app(ScheduleBannerHandler::class)->handle(new ScheduleBanner($listing, 1))))->toBe('phone_image:required');
    });

    it('takes an Arabic and an English image each when its artwork has words on it', function () {
        Cx::editor([ContentPermissions::BANNER_MANAGE]);

        expect(bannerRefusal(fn () => Cx::banner(['hasWords' => true, 'images' => ['PHONE:any' => Cx::image()]])))->toBe('images:choice');

        $banner = Cx::banner(['hasWords' => true, 'images' => ['PHONE:ar' => Cx::image()]]);
        expect(bannerRefusal(fn () => app(ScheduleBannerHandler::class)->handle(new ScheduleBanner($banner, 1))))->toBe('phone_image_en:required');

        $both = Cx::banner(['hasWords' => true, 'images' => ['PHONE:ar' => Cx::image(), 'PHONE:en' => Cx::image()]]);
        app(ScheduleBannerHandler::class)->handle(new ScheduleBanner($both, 1));

        expect(DB::table('content.banner_images')->where('banner_id', $both)->orderBy('locale')->pluck('locale')->all())->toBe(['ar', 'en']);
    });

    it('schedules with both descriptions, from now when no start is given, and refuses an end already past', function () {
        Cx::editor([ContentPermissions::BANNER_MANAGE]);
        $noEnglish = Cx::banner(['altEn' => null]);
        $past = Cx::banner(['endsAt' => Cx::typed(-10)]);
        $banner = Cx::banner(['endsAt' => Cx::typed(600)]);

        expect(bannerRefusal(fn () => app(ScheduleBannerHandler::class)->handle(new ScheduleBanner($noEnglish, 1))))->toBe('alt:english')
            ->and(bannerRefusal(fn () => app(ScheduleBannerHandler::class)->handle(new ScheduleBanner($past, 1))))->toBe('ends_at:past');

        app(ScheduleBannerHandler::class)->handle(new ScheduleBanner($banner, 1));

        expect(CarbonImmutable::parse((string) DB::table('content.banners')->where('id', $banner)->value('starts_at'))->equalTo(CarbonImmutable::now()->startOfSecond()))->toBeTrue()
            ->and(app(BannersHandler::class)->list(Fx::storeId('sa'), 'HOME_PHONE')['rows'][2]['state'])->toBe('LIVE')
            ->and(Fx::audits('content.banner.scheduled', $banner))->toBe(1);
    });

    it('keeps a scheduled banner to Schedule\'s rules when it is changed: its English can\'t be blanked', function () {
        Cx::editor([ContentPermissions::BANNER_MANAGE]);
        $banner = Cx::banner();
        app(ScheduleBannerHandler::class)->handle(new ScheduleBanner($banner, 1));
        $image = (string) DB::table('content.banner_images')->where('banner_id', $banner)->value('media_id');
        $change = static fn (array $with) => app(SaveBannerHandler::class)->handle(new SaveBanner(...[
            'bannerId' => $banner, 'storeId' => null, 'version' => bannerVersion($banner), 'placement' => 'HOME_PHONE', 'categoryId' => null,
            'name' => 'Summer', 'link' => null, 'hasWords' => false, 'altAr' => 'تخفيضات', 'altEn' => 'Sale', 'startsAt' => null, 'endsAt' => null,
            'images' => ['PHONE:any' => $image], ...$with,
        ]));

        expect(bannerRefusal(fn () => $change(['altEn' => null])))->toBe('alt:english')
            ->and(bannerRefusal(fn () => $change(['images' => []])))->toBe('phone_image:required')
            ->and(bannerRefusal(fn () => $change(['placement' => 'LISTING'])))->toBe('placement:choice');

        $change(['name' => 'Summer 2027']);
        expect(DB::table('content.banners')->where('id', $banner)->value('name'))->toBe('Summer 2027');
    });

    it('ends a live banner now, takes a scheduled one back to draft, and deletes only a draft', function () {
        Cx::editor([ContentPermissions::BANNER_MANAGE]);
        $live = Cx::banner();
        app(ScheduleBannerHandler::class)->handle(new ScheduleBanner($live, 1));
        $later = Cx::banner(['startsAt' => Cx::typed(120)]);
        app(ScheduleBannerHandler::class)->handle(new ScheduleBanner($later, 1));

        expect(bannerRefusal(fn () => app(EndBannerHandler::class)->handle(new EndBanner($later))))->toBe('state:state')
            ->and(bannerRefusal(fn () => app(BannerBackToDraftHandler::class)->handle(new BannerBackToDraft($live))))->toBe('state:state');

        app(EndBannerHandler::class)->handle(new EndBanner($live));
        app(BannerBackToDraftHandler::class)->handle(new BannerBackToDraft($later));

        expect(fn () => app(DeleteBannerHandler::class)->handle(new DeleteBanner($live)))->toThrow(NotDeletable::class)
            ->and(DB::table('content.banners')->where('id', $later)->value('is_scheduled'))->toBeFalse();

        app(DeleteBannerHandler::class)->handle(new DeleteBanner($later));

        $states = array_column(app(BannersHandler::class)->list(Fx::storeId('sa'), 'HOME_PHONE')['rows'], 'state', 'id');
        expect($states)->toBe([$live => 'ENDED'])
            ->and(DB::table('content.banner_images')->where('banner_id', $later)->exists())->toBeFalse();
    });

    it('orders a placement\'s banners as dragged - the whole order, each once - and counts each placement', function () {
        Cx::editor([ContentPermissions::BANNER_MANAGE]);
        $first = Cx::banner();
        $second = Cx::banner();
        Cx::banner(['placement' => 'HOME_HERO', 'images' => ['DESKTOP:any' => Cx::image()]]);

        expect(bannerRefusal(fn () => app(OrderBannersHandler::class)->handle(new OrderBanners(Fx::storeId('sa'), 'HOME_PHONE', [$second]))))->toBe('banners:order');

        app(OrderBannersHandler::class)->handle(new OrderBanners(Fx::storeId('sa'), 'HOME_PHONE', [$second, $first]));
        $list = app(BannersHandler::class)->list(Fx::storeId('sa'), 'HOME_PHONE');

        expect(array_column($list['rows'], 'id'))->toBe([$second, $first])
            ->and($list['counts'])->toBe(['HOME_HERO' => 1, 'HOME_PHONE' => 2, 'LISTING' => 0]);
    });
});

describe('§1.1 rule 11 and §4.1: an ended banner or pop-up is history', function () {
    it('refuses a change to an ended banner, so it can\'t be dated back to live', function () {
        Cx::editor([ContentPermissions::BANNER_MANAGE]);
        $banner = Cx::banner();
        app(ScheduleBannerHandler::class)->handle(new ScheduleBanner($banner, 1));
        app(EndBannerHandler::class)->handle(new EndBanner($banner));
        $image = (string) DB::table('content.banner_images')->where('banner_id', $banner)->value('media_id');

        expect(bannerRefusal(fn () => app(SaveBannerHandler::class)->handle(new SaveBanner($banner, null, bannerVersion($banner), 'HOME_PHONE', null, 'Summer', null, false, 'تخفيضات', 'Sale', Cx::typed(1), Cx::typed(600), ['PHONE:any' => $image]))))->toBe('state:state')
            ->and(app(BannersHandler::class)->list(Fx::storeId('sa'), 'HOME_PHONE')['rows'][0]['state'])->toBe('ENDED');
    });

    it('refuses a change to an ended pop-up', function () {
        Cx::editor([ContentPermissions::POPUP_MANAGE]);
        $popup = Cx::popup();
        app(SchedulePopupHandler::class)->handle(new SchedulePopup($popup, 1));
        app(EndPopupHandler::class)->handle(new EndPopup($popup));

        expect(bannerRefusal(fn () => app(SavePopupHandler::class)->handle(new SavePopup($popup, null, popupVersion($popup), 'Welcome', 'أهلًا', 'Welcome', 'نص', 'Text', null, null, null, null, null, Cx::typed(1), Cx::typed(600)))))->toBe('state:state')
            ->and(array_column(app(PopupsHandler::class)->list(Fx::storeId('sa')), 'state', 'id'))->toBe([$popup => 'ENDED']);
    });
});

describe('§1.9 smart bars', function () {
    it('shows a message of 100 characters per language above a category\'s lists, with optional dates, both languages on save', function () {
        Cx::editor([ContentPermissions::BAR_MANAGE]);
        $category = Px::category();
        $save = static fn (array $with) => app(SaveSmartBarHandler::class)->handle(new SaveSmartBar(...[
            'smartBarId' => null, 'storeId' => Fx::storeId('sa'), 'scope' => 'CATEGORY', 'categoryId' => $category, 'runId' => null,
            'messageAr' => 'خصم على المطابخ', 'messageEn' => 'Kitchens on sale', 'link' => '/categories/kitchens', ...$with,
        ]));

        expect(bannerRefusal(fn () => $save(['messageAr' => str_repeat('خ', 101)])))->toBe('message_ar:too_long')
            ->and(bannerRefusal(fn () => $save(['categoryId' => null])))->toBe('category:category')
            ->and(bannerRefusal(fn () => $save(['startsAt' => Cx::typed(60), 'endsAt' => Cx::typed(30)])))->toBe('ends_at:dates');

        $bar = $save(['startsAt' => Cx::typed(60)]);
        // The dialog sends every field again, the dates included.
        $save(['smartBarId' => $bar, 'storeId' => null, 'messageEn' => 'Kitchens, on sale', 'startsAt' => Cx::typed(60)]);

        expect(app(BarsHandler::class)->smartBars(Fx::storeId('sa')))->toHaveCount(1)
            ->and(app(BarsHandler::class)->smartBars(Fx::storeId('sa'))[0])->toMatchArray(['categoryId' => $category, 'messageEn' => 'Kitchens, on sale', 'state' => 'SCHEDULED']);

        app(DeleteSmartBarHandler::class)->handle(new DeleteSmartBar($bar));
        expect(DB::table('content.smart_bars')->count())->toBe(0)
            ->and(Fx::audits('content.smart_bar.deleted', $bar))->toBe(1);
    });

    it('waits for campaign runs before a campaign\'s bar can be made (plan PR 6)', function () {
        Cx::editor([ContentPermissions::BAR_MANAGE]);

        expect(bannerRefusal(fn () => app(SaveSmartBarHandler::class)->handle(new SaveSmartBar(null, Fx::storeId('sa'), 'CAMPAIGN', null, strtolower((string) Str::ulid()), 'خصم', 'Sale', null))))->toBe('scope:choice');
    });
});

describe('§1.10 the pop-up', function () {
    it('holds a title of 80 and a text of 240 per language, a button with its link, a delay of 3 to 60 seconds, 8 if none', function () {
        Cx::editor([ContentPermissions::POPUP_MANAGE]);

        expect(bannerRefusal(fn () => Cx::popup(['titleAr' => str_repeat('ع', 81)])))->toBe('title_ar:too_long')
            ->and(bannerRefusal(fn () => Cx::popup(['textAr' => str_repeat('ن', 241)])))->toBe('text_ar:too_long')
            ->and(bannerRefusal(fn () => Cx::popup(['link' => null])))->toBe('link:required')
            ->and(bannerRefusal(fn () => Cx::popup(['buttonAr' => null, 'buttonEn' => null])))->toBe('button_ar:required')
            ->and(bannerRefusal(fn () => Cx::popup(['delaySeconds' => 2])))->toBe('delay_seconds:range')
            ->and(bannerRefusal(fn () => Cx::popup(['delaySeconds' => 61])))->toBe('delay_seconds:range');

        $popup = Cx::popup(['buttonAr' => null, 'buttonEn' => null, 'link' => null]);
        expect(DB::table('content.popups')->where('id', $popup)->value('delay_seconds'))->toBe(8);
    });

    it('refuses a second pop-up over the first in one store, naming it and its dates; a draft or another store never clashes', function () {
        Cx::editor([ContentPermissions::POPUP_MANAGE], ['sa', 'ae']);
        $first = Cx::popup(['name' => 'Ramadan', 'endsAt' => Cx::typed(600)]);
        app(SchedulePopupHandler::class)->handle(new SchedulePopup($first, 1));
        $second = Cx::popup(['startsAt' => Cx::typed(300)]);
        $elsewhere = Cx::popup([], 'ae');

        try {
            app(SchedulePopupHandler::class)->handle(new SchedulePopup($second, 1));
            throw new LogicException('Not refused.');
        } catch (PopupClash $clash) {
            expect($clash->popup)->toBe('Ramadan')
                ->and($clash->ends)->toBe(Cx::typed(600))
                ->and(DB::table('content.popups')->where('id', $second)->value('is_scheduled'))->toBeFalse();
        }

        app(SchedulePopupHandler::class)->handle(new SchedulePopup($elsewhere, 1));
        $after = Cx::popup(['startsAt' => Cx::typed(600)]);
        app(SchedulePopupHandler::class)->handle(new SchedulePopup($after, 1));

        expect(array_column(app(PopupsHandler::class)->list(Fx::storeId('sa')), 'state', 'id'))->toMatchArray([$first => 'LIVE', $second => 'DRAFT', $after => 'SCHEDULED']);
    });

    it('checks a clash under the store\'s pop-up lock, inside the schedule\'s own transaction', function () {
        Cx::editor([ContentPermissions::POPUP_MANAGE]);
        $popup = Cx::popup();
        /** @var ArrayObject<int, array{0: mixed, 1: int}> $locks */
        $locks = new ArrayObject;
        DB::listen(function (QueryExecuted $query) use ($locks): void {
            if (str_contains($query->sql, 'pg_advisory_xact_lock')) {
                $locks[] = [$query->bindings[0] ?? null, DB::transactionLevel()];
            }
        });

        app(SchedulePopupHandler::class)->handle(new SchedulePopup($popup, 1));

        // RefreshDatabase's transaction is level 1; the schedule's own is level 2.
        expect($locks->getArrayCopy())->toContain(['content:popups:'.Fx::storeId('sa'), 2]);
    });

    it('keeps a scheduled pop-up to Schedule\'s rules on a change, ends a live one now, takes a scheduled one back, deletes only a draft', function () {
        Cx::editor([ContentPermissions::POPUP_MANAGE]);
        $live = Cx::popup(['endsAt' => Cx::typed(60)]);
        app(SchedulePopupHandler::class)->handle(new SchedulePopup($live, 1));
        $later = Cx::popup(['startsAt' => Cx::typed(120)]);
        app(SchedulePopupHandler::class)->handle(new SchedulePopup($later, 1));

        expect(bannerRefusal(fn () => app(SavePopupHandler::class)->handle(new SavePopup($live, null, popupVersion($live), 'Welcome', 'أهلًا', null, 'نص', 'Text', null, null, null, null, null, null, Cx::typed(60)))))->toBe('title:english')
            ->and(fn () => app(DeletePopupHandler::class)->handle(new DeletePopup($later)))->toThrow(NotDeletable::class);

        app(EndPopupHandler::class)->handle(new EndPopup($live));
        app(PopupBackToDraftHandler::class)->handle(new PopupBackToDraft($later));
        app(DeletePopupHandler::class)->handle(new DeletePopup($later));

        expect(array_column(app(PopupsHandler::class)->list(Fx::storeId('sa')), 'state', 'id'))->toBe([$live => 'ENDED'])
            ->and(Fx::audits('content.popup.ended', $live))->toBe(1);
    });
});
