<?php

declare(strict_types=1);

namespace Modules\Feedback\Application\Query\ViewCustomerWishlist;

use Modules\Access\Public\Contracts\AccessApi;
use Modules\Feedback\Application\FeedbackPermissions;
use Modules\Feedback\Application\Query\FeedbackQueries;
use Modules\Feedback\Application\Query\ReadInputs;
use Modules\Feedback\Application\Query\WishlistGroup;
use Modules\Feedback\Application\Query\WishlistGroups;
use Modules\Feedback\Domain\Exception\InvalidFeedbackAttribute;
use Shared\Application\ActorContext;
use Shared\Application\ActorType;
use Shared\Application\Authorizer;
use Shared\Application\Unauthorized;
use Shared\Domain\ValueObject\StoreId;
use Shared\Infrastructure\Persistence\Ulids;

/**
 * A customer's wishlist as staff read it (feedback.md §1.4, §3): `feedback.wishlist.view`, **only its
 * items of the stores where the reader holds it** — a reader of one store never sees another's — and
 * only for a customer the reader may see at all (access.md amendment 44(c)). An off store's items stay
 * listed, marked, as Loyalty's points do. A customer the reader may not see, or no such customer,
 * reads as an empty list — the same answer for both, no new error (accepted, 2026-10-19).
 */
final readonly class ViewCustomerWishlistHandler
{
    public const string PERMISSION = FeedbackPermissions::WISHLIST_VIEW;

    public function __construct(
        private Authorizer $authorizer,
        private ActorContext $actors,
        private AccessApi $access,
        private FeedbackQueries $queries,
        private WishlistGroups $groups,
    ) {}

    /**
     * @return list<WishlistGroup>
     *
     * @throws Unauthorized|InvalidFeedbackAttribute
     */
    public function handle(ViewCustomerWishlist $query): array
    {
        $held = $this->authorizer->storesWith(self::PERMISSION);
        $actor = $this->actors->current();

        if ($held === [] || $actor->type !== ActorType::Staff || $actor->id === null) {
            throw new Unauthorized(self::PERMISSION);
        }

        $locale = ReadInputs::locale($query->locale);
        $customerId = strtolower(trim($query->customerId));
        $customer = Ulids::valid($customerId) ? $this->access->customer($customerId) : null;

        if ($customer === null) {
            return [];
        }

        $visible = $this->access->customerViewStores();

        if ($visible !== null && ! in_array(strtolower($customer->homeStoreId), array_map(static fn (StoreId $store): string => $store->value, $visible), true)) {
            return [];
        }

        $stores = $held === null ? null : array_map(static fn (StoreId $store): string => $store->value, $held);

        return $this->groups->of($this->queries->wishlistOf($customerId, $stores), $locale);
    }
}
