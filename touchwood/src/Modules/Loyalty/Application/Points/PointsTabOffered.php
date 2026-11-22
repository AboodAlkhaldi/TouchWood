<?php

declare(strict_types=1);

namespace Modules\Loyalty\Application\Points;

use Modules\Access\Public\Contracts\CustomerAccountPageCondition;
use Modules\Loyalty\Application\Settings\ProgrammeSettings as Keys;
use Modules\Loyalty\Domain\Repository\PointsAccounts;
use Modules\Platform\Public\Contracts\PlatformApi;
use Shared\Application\StoreContext;

/**
 * When the shop's account offers its Points tab (loyalty.md §4.1 L7; owner, 2026-10-19, answer 4):
 * while the store's programme is on, or the customer has an account there - points, or history -
 * so a programme a store does not run is never advertised, and nobody loses sight of points they
 * still hold.
 *
 * Asked on every shop page of a signed-in customer (access.md amendment 72(d)): the switch comes
 * from the settings the page already read; only an off programme costs a query of its own.
 */
final readonly class PointsTabOffered implements CustomerAccountPageCondition
{
    public function __construct(
        private StoreContext $stores,
        private PlatformApi $platform,
        private PointsAccounts $accounts,
    ) {}

    public function offers(string $customerId): bool
    {
        $store = $this->stores->current();

        return $this->platform->setting(Keys::ENABLED, $store)->bool()
            || $this->accounts->find(strtolower($customerId), $store) !== null;
    }
}
