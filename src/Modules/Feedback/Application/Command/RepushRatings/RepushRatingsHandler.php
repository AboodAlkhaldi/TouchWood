<?php

declare(strict_types=1);

namespace Modules\Feedback\Application\Command\RepushRatings;

use Illuminate\Database\ConnectionInterface;
use Modules\Catalog\Public\Contracts\ListingFacts;
use Modules\Feedback\Application\FeedbackPermissions;
use Modules\Feedback\Application\FeedbackSettings;
use Modules\Platform\Public\Contracts\PlatformApi;
use Shared\Application\Authorizer;
use Shared\Application\PermissionScope;
use Shared\Application\Unauthorized;

/**
 * The repair, run by hand (feedback.md §1.2): whenever Catalog's lists may not match Feedback — a
 * switch's push that failed after its setting was saved — every store's switch as it is now, and
 * every product's rating, a thousand at a time. Pushing what is already there changes nothing.
 */
final readonly class RepushRatingsHandler
{
    public const string PERMISSION = FeedbackPermissions::RATINGS_REPUSH;

    private const int CHUNK = 1000;

    public function __construct(
        private Authorizer $authorizer,
        private PlatformApi $platform,
        private ListingFacts $listing,
        private ConnectionInterface $db,
    ) {}

    /**
     * @return array{stores: int, products: int}
     *
     * @throws Unauthorized
     */
    public function handle(RepushRatings $command): array
    {
        $this->authorizer->authorize(self::PERMISSION, PermissionScope::global());
        $stores = 0;
        $products = 0;

        foreach ($this->platform->allStores() as $store) {
            $id = $store->storeId();
            $this->db->transaction(fn () => $this->listing->ratingsShown($id, $this->platform->setting(FeedbackSettings::RATINGS_IN_LISTS, $id)->bool()), 3);
            $stores++;
        }

        $this->db->table('feedback.product_ratings')->orderBy('product_id')->chunk(self::CHUNK, function ($rows) use (&$products): void {
            $ratings = [];

            foreach ($rows as $row) {
                $ratings[(string) $row->product_id] = ['sum' => (int) $row->rating_sum, 'count' => (int) $row->rating_count];
            }

            $this->db->transaction(fn () => $this->listing->ratings($ratings), 3);
            $products += count($ratings);
        });

        return ['stores' => $stores, 'products' => $products];
    }
}
