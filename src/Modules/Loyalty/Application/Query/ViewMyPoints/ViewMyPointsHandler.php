<?php

declare(strict_types=1);

namespace Modules\Loyalty\Application\Query\ViewMyPoints;

use Modules\Loyalty\Application\LoyaltyPermissions;
use Modules\Loyalty\Application\Points\Inputs;
use Modules\Loyalty\Application\Query\PointsView;
use Modules\Loyalty\Application\Query\PointsViews;
use Modules\Loyalty\Domain\Exception\InvalidPointsAttribute;
use Modules\Loyalty\Domain\Repository\PointsAccounts;
use Shared\Application\ActorContext;
use Shared\Application\ActorType;
use Shared\Application\Authorizer;
use Shared\Application\PermissionScope;
use Shared\Application\Unauthorized;

/**
 * A customer's own points in a store (loyalty.md §3): `loyalty.points.view_own`, about their own
 * account only — the one the signed-in customer is. No account yet: a balance of 0.
 */
final readonly class ViewMyPointsHandler
{
    public const string PERMISSION = LoyaltyPermissions::VIEW_OWN;

    public function __construct(
        private Authorizer $authorizer,
        private ActorContext $actors,
        private PointsAccounts $accounts,
        private PointsViews $views,
    ) {}

    /**
     * @throws InvalidPointsAttribute|Unauthorized
     */
    public function handle(ViewMyPoints $query): PointsView
    {
        $this->authorizer->authorize(self::PERMISSION, PermissionScope::global());
        $actor = $this->actors->current();

        if ($actor->type !== ActorType::Customer || $actor->id === null) {
            throw new Unauthorized(self::PERMISSION);
        }

        $store = Inputs::store($query->storeId);

        return $this->views->of($this->accounts->find($actor->id, $store), $store, false, $query->beforeAt, $query->beforeId, $query->perPage);
    }
}
