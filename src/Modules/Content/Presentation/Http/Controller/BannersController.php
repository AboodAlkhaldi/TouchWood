<?php

declare(strict_types=1);

namespace Modules\Content\Presentation\Http\Controller;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Response;
use Modules\Content\Application\Command\BannerBackToDraft\BannerBackToDraft;
use Modules\Content\Application\Command\BannerBackToDraft\BannerBackToDraftHandler;
use Modules\Content\Application\Command\DeleteBanner\DeleteBanner;
use Modules\Content\Application\Command\DeleteBanner\DeleteBannerHandler;
use Modules\Content\Application\Command\EndBanner\EndBanner;
use Modules\Content\Application\Command\EndBanner\EndBannerHandler;
use Modules\Content\Application\Command\OrderBanners\OrderBanners;
use Modules\Content\Application\Command\OrderBanners\OrderBannersHandler;
use Modules\Content\Application\Command\SaveBanner\SaveBanner;
use Modules\Content\Application\Command\SaveBanner\SaveBannerHandler;
use Modules\Content\Application\Command\ScheduleBanner\ScheduleBanner;
use Modules\Content\Application\Command\ScheduleBanner\ScheduleBannerHandler;
use Modules\Content\Application\ContentPermissions;
use Modules\Content\Application\Query\Banners\BannersHandler;
use Modules\Content\Application\Query\ContentActions\ContentActionsForReader;
use Modules\Content\Domain\Model\BannerPlacement;
use Modules\Content\Presentation\Http\Resource\BannerData;
use Modules\Content\Presentation\Http\Resource\BannerFormPageData;
use Modules\Content\Presentation\Http\Resource\BannerRowData;
use Modules\Content\Presentation\Http\Resource\BannersPageData;
use Modules\Content\Presentation\Http\Resource\ContentStoreData;
use Modules\Platform\Public\Contracts\PlatformApi;
use Modules\Platform\Public\Dto\StoreDto;
use Shared\Domain\Error\DomainError;
use Shared\Domain\ValueObject\StoreId;

/*
| W3 · Banners (content.md §4.4, §1.6): a placement's banners in order - tabs by placement with counts - each banner's
| own page (Add Banner, and each one's), the order dragged, and what a row's menu does: End Now, Back to Draft, Delete.
| Under `content.banner.manage` in the store, which each handler asks again.
*/
final readonly class BannersController
{
    /** @var list<string> */
    private const array WORDS = ['content::admin_banners', 'catalog::admin'];

    /** @var list<string> */
    private const array BOXES = [
        'name', 'link', 'alt_ar', 'alt_en', 'category', 'placement', 'images', 'starts_at', 'ends_at',
        'desktop_image', 'desktop_image_ar', 'desktop_image_en', 'phone_image', 'phone_image_ar', 'phone_image_en',
    ];

    private const string LIST = '/admin/content/banners';

    public function __construct(
        private ContentScreens $screens,
    ) {}

    public function index(Request $request, BannersHandler $banners): Response|JsonResponse
    {
        $store = $this->screens->store($request, ContentPermissions::BANNER_MANAGE);
        $placement = self::placement($request);
        $list = $banners->list($store->id, $placement);
        $thumbnails = $this->screens->images(array_map(static fn (array $row): ?string => is_string($row['thumbnail'] ?? null) ? $row['thumbnail'] : null, $list['rows']));
        $rows = array_map(static fn (array $row): BannerRowData => BannerRowData::fromRow($row, is_string($row['thumbnail'] ?? null) ? ($thumbnails[$row['thumbnail']]->thumbnail ?? null) : null), $list['rows']);

        if ($request->expectsJson()) {
            return new JsonResponse(['banners' => array_map(static fn (BannerRowData $row): array => $row->toArray(), $rows), 'counts' => $list['counts']]);
        }

        return $this->screens->render('Content/Admin/Banners/Index', (new BannersPageData(
            $this->screens->stores(ContentPermissions::BANNER_MANAGE),
            $store->code,
            $store->timezone,
            $placement,
            $list['counts'],
            $rows,
            $this->screens->categories(),
        ))->toArray(), self::WORDS);
    }

    public function create(Request $request, ContentActionsForReader $actions): Response
    {
        $store = $this->screens->store($request, ContentPermissions::BANNER_MANAGE);

        return $this->form($store, self::placement($request), null, $actions);
    }

    public function edit(string $banner, BannersHandler $banners, PlatformApi $platform, ContentActionsForReader $actions): Response
    {
        $fields = $banners->banner($banner);
        $read = BannerData::fromFields($fields);
        $store = $platform->store(StoreId::fromString((string) $fields['store_id']));

        /** @var StoreDto $store the handler found the banner in it */
        return $this->form($store, $read->placement, $read, $actions);
    }

    public function store(Request $request, SaveBannerHandler $save, ScheduleBannerHandler $schedule, BannersHandler $banners): RedirectResponse
    {
        return $this->save($request, null, $save, $schedule, $banners);
    }

    public function update(Request $request, string $banner, SaveBannerHandler $save, ScheduleBannerHandler $schedule, BannersHandler $banners): RedirectResponse
    {
        return $this->save($request, $banner, $save, $schedule, $banners);
    }

    public function order(Request $request, OrderBannersHandler $order): RedirectResponse
    {
        try {
            $order->handle(new OrderBanners(Inputs::text($request, 'store'), (string) Inputs::text($request, 'placement'), Inputs::texts($request, 'ids')));
        } catch (DomainError $error) {
            return Refusals::back($request, $error, []);
        }

        return back();
    }

    public function end(Request $request, string $banner, EndBannerHandler $end): RedirectResponse
    {
        return self::act($request, fn () => $end->handle(new EndBanner($banner)), 'content::admin_banners.ended');
    }

    public function draft(Request $request, string $banner, BannerBackToDraftHandler $draft): RedirectResponse
    {
        return self::act($request, fn () => $draft->handle(new BannerBackToDraft($banner)), 'content::admin_banners.drafted');
    }

    public function delete(Request $request, string $banner, DeleteBannerHandler $delete): RedirectResponse
    {
        return self::act($request, fn () => $delete->handle(new DeleteBanner($banner)), 'content::admin_banners.deleted');
    }

    /**
     * Save Draft, or Schedule Banner: saved, then scheduled at the version the save made. A new one whose Schedule is
     * refused - an image still being prepared, say - stays a draft, and its own page says why.
     */
    private function save(Request $request, ?string $id, SaveBannerHandler $save, ScheduleBannerHandler $schedule, BannersHandler $banners): RedirectResponse
    {
        try {
            $saved = $save->handle(new SaveBanner(
                $id,
                Inputs::text($request, 'store'),
                Inputs::version($request),
                (string) Inputs::text($request, 'placement'),
                Inputs::text($request, 'category_id'),
                (string) Inputs::typed($request, 'name'),
                Inputs::text($request, 'link'),
                Inputs::flag($request, 'has_words'),
                Inputs::typed($request, 'alt_ar'),
                Inputs::typed($request, 'alt_en'),
                Inputs::text($request, 'starts_at'),
                Inputs::text($request, 'ends_at'),
                Inputs::map($request, 'images'),
            ));
        } catch (DomainError $error) {
            return Refusals::back($request, $error, self::BOXES);
        }

        $fields = $banners->banner($saved);
        $read = BannerData::fromFields($fields);
        $back = self::LIST.'?'.http_build_query(['store' => $this->code($fields['store_id']), 'placement' => $read->placement]);

        if (Inputs::text($request, 'intent') === 'schedule' && $read->state === 'DRAFT') {
            try {
                $schedule->handle(new ScheduleBanner($saved, $read->version));
            } catch (DomainError $error) {
                return Refusals::to(self::LIST."/{$saved}", $error, self::BOXES);
            }

            return redirect($back)->with('status', __('content::admin_banners.scheduled'));
        }

        return redirect($back)->with('status', __('content::admin_banners.saved'));
    }

    private function form(StoreDto $store, string $placement, ?BannerData $banner, ContentActionsForReader $actions): Response
    {
        $images = $banner === null ? [] : $this->screens->images(array_values($banner->images));
        $bySlot = [];

        foreach ($banner->images ?? [] as $slot => $mediaId) {
            if (isset($images[$mediaId])) {
                $bySlot[$slot] = $images[$mediaId];
            }
        }

        return $this->screens->render('Content/Admin/Banners/Form', (new BannerFormPageData(
            ContentStoreData::of($store),
            $store->timezone,
            $placement,
            $banner,
            $bySlot,
            $this->screens->categories(),
            $actions->translationReady(),
        ))->toArray(), self::WORDS);
    }

    private static function placement(Request $request): string
    {
        return (BannerPlacement::tryFrom((string) Inputs::text($request, 'placement')) ?? BannerPlacement::HomeHero)->value;
    }

    private function code(mixed $storeId): string
    {
        foreach ($this->screens->stores(ContentPermissions::BANNER_MANAGE) as $store) {
            if ($store->id === strtolower((string) $storeId)) {
                return $store->code;
            }
        }

        return '';
    }

    /**
     * @param  callable(): void  $change
     */
    private static function act(Request $request, callable $change, string $toast): RedirectResponse
    {
        try {
            $change();
        } catch (DomainError $error) {
            return Refusals::back($request, $error, []);
        }

        return back()->with('status', __($toast));
    }
}
