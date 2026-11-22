<?php

declare(strict_types=1);

namespace Modules\Feedback\Application\Review;

use Illuminate\Database\ConnectionInterface;
use Modules\Catalog\Public\Contracts\ListingFacts;
use Modules\Feedback\Application\FeedbackSettings;
use Modules\Platform\Public\Contracts\PlatformApi;
use Shared\Domain\ValueObject\StoreId;
use Throwable;

/**
 * A store's switch for ratings in its lists (feedback.md §1.2) told to Catalog, which never reads
 * Feedback: on Platform's `SettingChanged` for Feedback's own key — after the setting committed — the
 * store's value **as it is when read under the store's lock**, so two quick changes end with the
 * lists showing what the setting last says. A failure is reported, never thrown back at the admin
 * whose setting is saved: the repair (`feedback:ratings:repush`) tells Catalog again.
 */
final readonly class RatingsSwitch
{
    public function __construct(
        private PlatformApi $platform,
        private ListingFacts $listing,
        private ConnectionInterface $db,
    ) {}

    public function changed(string $key, ?string $storeId): void
    {
        if ($key !== FeedbackSettings::RATINGS_IN_LISTS || $storeId === null) {
            return;
        }

        try {
            $this->push(StoreId::fromString($storeId));
        } catch (Throwable $error) {
            report($error);
        }
    }

    /**
     * Tells Catalog the store's switch as it is now, under a lock on the store: two quick changes, or a
     * change and the repair (`RepushRatings`), each push the value read after the other's.
     */
    public function push(StoreId $store): void
    {
        $this->db->transaction(function () use ($store): void {
            $this->db->select('SELECT pg_advisory_xact_lock(hashtextextended(?, 0))', ['feedback:ratings-switch:'.$store->value], false);
            $this->listing->ratingsShown($store, $this->platform->setting(FeedbackSettings::RATINGS_IN_LISTS, $store)->bool());
        }, 3);
    }
}
