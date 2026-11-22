<?php

declare(strict_types=1);

namespace Modules\Content\Presentation\Frame;

use App\Http\StorefrontArea;
use Illuminate\Http\Request;
use Modules\Catalog\Public\Contracts\ShopListingSlot;
use Modules\Catalog\Public\Dto\ShopListingVisitDto;
use Modules\Content\Application\Query\Shop\ContentFrame;
use Modules\Content\Presentation\Http\ShopFrameOfRequest;
use Modules\Platform\Public\Dto\StoreDto;

/**
 * Content's slot above a product list (catalog.md amendment 41(a); content.md §1.9, §1.6 `LISTING`, §1.8 part 8): one
 * smart bar and one listing banner, from the frame's one cached read the page has already paid for - **no query of its
 * own**. A brand's or a search's list has no category, so only a campaign's smart bar and an every-list banner reach it.
 */
final readonly class ContentListingSlot implements ShopListingSlot
{
    public function __construct(
        private ShopFrameOfRequest $frames,
        private Request $request,
    ) {}

    public function read(ShopListingVisitDto $visit): ?array
    {
        $store = $this->request->attributes->get(StorefrontArea::RESOLVED_STORE);

        if (! $store instanceof StoreDto || $store->storeId()->value !== $visit->store->value) {
            return null;
        }

        ['kept' => $kept, 'now' => $now] = $this->frames->of($this->request, $store, $visit->locale);
        $slot = ContentFrame::listing($kept, $visit->locale, $visit->kind === ShopListingVisitDto::CATEGORY ? $visit->categoryPath : [], $now);

        return $slot['smartBar'] === null && $slot['banner'] === null ? null : $slot;
    }
}
