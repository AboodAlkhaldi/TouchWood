<?php

declare(strict_types=1);

namespace Modules\Feedback\Application\Review;

use Modules\Catalog\Public\Contracts\ListingFacts;
use Modules\Feedback\Application\FeedbackSettings;
use Modules\Platform\Public\Contracts\PlatformApi;
use Shared\Domain\ValueObject\StoreId;

/**
 * A store's switch for ratings in its lists (feedback.md §1.2) told to Catalog, which never reads
 * Feedback: on Platform's `SettingChanged` for Feedback's own key, the store's value as it now is.
 * Pushing the same value again changes nothing, so it needs no record of what it handled.
 */
final readonly class RatingsSwitch
{
    public function __construct(
        private PlatformApi $platform,
        private ListingFacts $listing,
    ) {}

    public function changed(string $key, ?string $storeId): void
    {
        if ($key !== FeedbackSettings::RATINGS_IN_LISTS || $storeId === null) {
            return;
        }

        $store = StoreId::fromString($storeId);
        $this->listing->ratingsShown($store, $this->platform->setting(FeedbackSettings::RATINGS_IN_LISTS, $store)->bool());
    }
}
