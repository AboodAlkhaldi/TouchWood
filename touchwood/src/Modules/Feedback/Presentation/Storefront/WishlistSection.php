<?php

declare(strict_types=1);

namespace Modules\Feedback\Presentation\Storefront;

use Modules\Catalog\Public\Contracts\ShopProductSection;
use Modules\Catalog\Public\Dto\ShopProductVisitDto;
use Modules\Feedback\Application\Shop\ShopFeedback;
use Modules\Feedback\Presentation\Http\Resource\ShopWishlistData;

/** The wishlist button's state (feedback.md §1.4), `feedback.wishlist`: nothing for a guest, who has no list. */
final readonly class WishlistSection implements ShopProductSection
{
    public function __construct(
        private ShopFeedback $feedback,
    ) {}

    public function read(ShopProductVisitDto $visit): ?array
    {
        if ($visit->customerId === null) {
            return null;
        }

        return (new ShopWishlistData($this->feedback->of($visit)->saved))->toArray();
    }
}
