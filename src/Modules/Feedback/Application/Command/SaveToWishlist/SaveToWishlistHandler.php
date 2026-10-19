<?php

declare(strict_types=1);

namespace Modules\Feedback\Application\Command\SaveToWishlist;

use Carbon\CarbonImmutable;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\QueryException;
use InvalidArgumentException;
use Modules\Catalog\Public\Contracts\CatalogApi;
use Modules\Feedback\Application\FeedbackPermissions;
use Modules\Feedback\Application\RemovedMeanwhile;
use Modules\Feedback\Domain\Exception\InvalidFeedbackAttribute;
use Modules\Feedback\Domain\Exception\WishlistFull;
use Modules\Feedback\Domain\Repository\Wishlist;
use Modules\Platform\Public\Contracts\PlatformApi;
use Shared\Application\ActorContext;
use Shared\Application\ActorType;
use Shared\Application\Authorizer;
use Shared\Application\PermissionScope;
use Shared\Application\Unauthorized;
use Shared\Domain\ValueObject\StoreId;
use Shared\Infrastructure\Persistence\Ulids;

/**
 * A customer saves a product (feedback.md §1.4): in an open store that sells it (answer 19). Saving
 * it again in the same store is the one item, unchanged; the same product in another store is another.
 * **At most 500 items** — counted under a lock on the customer, so two saved at once cannot both pass
 * it (answer 20: an advisory lock, Feedback having no customer row).
 */
final readonly class SaveToWishlistHandler
{
    public const string PERMISSION = FeedbackPermissions::WISHLIST_MANAGE;

    /** Items in a list, in every store (§1.4; accepted by the owner, 2026-10-19). */
    public const int MOST = 500;

    public function __construct(
        private Authorizer $authorizer,
        private ActorContext $actors,
        private PlatformApi $platform,
        private CatalogApi $catalog,
        private Wishlist $wishlist,
        private ConnectionInterface $db,
    ) {}

    /**
     * @throws Unauthorized|InvalidFeedbackAttribute|WishlistFull
     */
    public function handle(SaveToWishlist $command): void
    {
        $this->authorizer->authorize(self::PERMISSION, PermissionScope::global());
        $actor = $this->actors->current();

        if ($actor->type !== ActorType::Customer || $actor->id === null) {
            throw new Unauthorized(self::PERMISSION);
        }

        $customerId = strtolower($actor->id);

        try {
            $store = StoreId::fromString($command->storeId);
        } catch (InvalidArgumentException) {
            throw new InvalidFeedbackAttribute('store', 'not a store id');
        }

        if (! ($this->platform->store($store)->isActive ?? false)) {
            throw new InvalidFeedbackAttribute('store', 'no such store open');
        }

        $productId = strtolower(trim($command->productId));

        if (! Ulids::valid($productId) || ! in_array($productId, $this->catalog->productsSoldIn($store, [$productId]), true)) {
            throw new InvalidFeedbackAttribute('product', 'not sold in this store');
        }

        try {
            $this->db->transaction(function () use ($customerId, $productId, $store): void {
                $this->db->select('SELECT pg_advisory_xact_lock(hashtextextended(?, 0))', ["feedback:wishlist:{$customerId}"], false);

                if ($this->wishlist->holds($customerId, $productId, $store)) {
                    return;
                }

                if ($this->wishlist->count($customerId) >= self::MOST) {
                    throw new WishlistFull;
                }

                $this->wishlist->save($customerId, $productId, $store, CarbonImmutable::now());
            });
        } catch (QueryException $failed) {
            throw RemovedMeanwhile::of($failed);
        }
    }
}
