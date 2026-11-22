<?php

declare(strict_types=1);

namespace Modules\Feedback\Application\Command\RemoveFromWishlist;

use InvalidArgumentException;
use Modules\Feedback\Application\FeedbackPermissions;
use Modules\Feedback\Domain\Exception\InvalidFeedbackAttribute;
use Modules\Feedback\Domain\Repository\Wishlist;
use Shared\Application\ActorContext;
use Shared\Application\ActorType;
use Shared\Application\Authorizer;
use Shared\Application\PermissionScope;
use Shared\Application\Unauthorized;
use Shared\Domain\ValueObject\StoreId;
use Shared\Infrastructure\Persistence\Ulids;

/**
 * A customer removes an item (feedback.md §1.4) — whatever became of it: a product the store stopped
 * selling, archived, or a store switched off, each shown as unavailable, leaves the list the same way.
 * An item that is not there is nothing to do.
 */
final readonly class RemoveFromWishlistHandler
{
    public const string PERMISSION = FeedbackPermissions::WISHLIST_MANAGE;

    public function __construct(
        private Authorizer $authorizer,
        private ActorContext $actors,
        private Wishlist $wishlist,
    ) {}

    /**
     * @throws Unauthorized|InvalidFeedbackAttribute
     */
    public function handle(RemoveFromWishlist $command): void
    {
        $this->authorizer->authorize(self::PERMISSION, PermissionScope::global());
        $actor = $this->actors->current();

        if ($actor->type !== ActorType::Customer || $actor->id === null) {
            throw new Unauthorized(self::PERMISSION);
        }

        try {
            $store = StoreId::fromString($command->storeId);
        } catch (InvalidArgumentException) {
            throw new InvalidFeedbackAttribute('store', 'not a store id');
        }

        $productId = strtolower(trim($command->productId));

        if (! Ulids::valid($productId)) {
            throw new InvalidFeedbackAttribute('product', 'not a product id');
        }

        $this->wishlist->remove(strtolower($actor->id), $productId, $store);
    }
}
