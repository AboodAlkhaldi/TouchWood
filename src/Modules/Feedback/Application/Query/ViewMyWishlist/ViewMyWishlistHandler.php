<?php

declare(strict_types=1);

namespace Modules\Feedback\Application\Query\ViewMyWishlist;

use Modules\Feedback\Application\FeedbackPermissions;
use Modules\Feedback\Application\Query\FeedbackQueries;
use Modules\Feedback\Application\Query\ReadInputs;
use Modules\Feedback\Application\Query\WishlistGroup;
use Modules\Feedback\Application\Query\WishlistGroups;
use Modules\Feedback\Domain\Exception\InvalidFeedbackAttribute;
use Shared\Application\Authorizer;
use Shared\Application\PermissionScope;
use Shared\Application\Unauthorized;

/**
 * "My wishlist" (feedback.md §1.4, §3): `feedback.own.view`, the signed-in customer's own — every
 * item, grouped by the store it was saved in; one that store no longer sells, or of a store switched
 * off, shown as unavailable (answer 17). The page of 500 items reads Catalog once per store (§8).
 */
final readonly class ViewMyWishlistHandler
{
    public const string PERMISSION = FeedbackPermissions::OWN_VIEW;

    public function __construct(
        private Authorizer $authorizer,
        private ReadInputs $inputs,
        private FeedbackQueries $queries,
        private WishlistGroups $groups,
    ) {}

    /**
     * @return list<WishlistGroup>
     *
     * @throws Unauthorized|InvalidFeedbackAttribute
     */
    public function handle(ViewMyWishlist $query): array
    {
        $this->authorizer->authorize(self::PERMISSION, PermissionScope::global());
        $customerId = $this->inputs->customer(self::PERMISSION);

        return $this->groups->of($this->queries->wishlistOf($customerId, null), ReadInputs::locale($query->locale));
    }
}
