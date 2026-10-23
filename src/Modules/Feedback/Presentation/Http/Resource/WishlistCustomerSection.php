<?php

declare(strict_types=1);

namespace Modules\Feedback\Presentation\Http\Resource;

use DateTimeZone;
use Illuminate\Contracts\Foundation\Application;
use Modules\Access\Public\Contracts\CustomerPageSection;
use Modules\Feedback\Application\Query\ViewCustomerWishlist\ViewCustomerWishlist;
use Modules\Feedback\Application\Query\ViewCustomerWishlist\ViewCustomerWishlistHandler;
use Modules\Feedback\Application\Query\WishlistGroup;
use Modules\Feedback\Application\Query\WishlistItemRow;
use Modules\Platform\Public\Contracts\PlatformApi;
use Shared\Application\Unauthorized;
use Shared\Domain\ValueObject\StoreId;

/**
 * F4 - a customer's wishlist on the staff customer page (feedback.md §4.1; access.md amendment 72(a)):
 * one block per store the reader may read wishlists in, each saved product with its date and "Not sold
 * now" marked. Nothing - no section at all - for a reader without the job; an empty list for a customer
 * the reader may not see (§3).
 */
final readonly class WishlistCustomerSection implements CustomerPageSection
{
    public function __construct(
        private Application $app,
        private ViewCustomerWishlistHandler $handler,
        private PlatformApi $platform,
    ) {}

    public function sectionFor(string $customerId): ?array
    {
        $locale = $this->app->getLocale() === 'en' ? 'en' : 'ar';

        try {
            $groups = $this->handler->handle(new ViewCustomerWishlist($customerId, $locale));
        } catch (Unauthorized) {
            return null;
        }

        return (new WishlistSectionData(array_map(function (WishlistGroup $group) use ($locale): WishlistStoreData {
            $code = $this->platform->store(StoreId::fromString($group->storeId))?->code;

            return new WishlistStoreData(
                $group->storeName,
                $group->storeOpen,
                array_map(static fn (WishlistItemRow $item): WishlistItemData => new WishlistItemData(
                    new ProductCardData(
                        $item->product->productId,
                        $item->product->name,
                        $code === null || $item->product->slug === null || $item->product->removed ? null : '/'.strtolower($code).'/'.$locale.'/'.$item->product->slug,
                        null,
                        ! $item->unavailable,
                        $item->product->archived,
                        $item->product->removed,
                    ),
                    $item->savedAt->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d\TH:i:s.vP'),
                ), $group->items),
            );
        }, $groups)))->toArray();
    }
}
