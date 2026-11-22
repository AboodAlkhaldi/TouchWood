<?php

declare(strict_types=1);

namespace Modules\Loyalty\Application\Query\CustomerPointsInStores;

use Carbon\CarbonImmutable;
use Modules\Loyalty\Application\LoyaltyPermissions;
use Modules\Loyalty\Application\Points\ProgrammeReader;
use Modules\Loyalty\Application\Points\StaffPointsAction;
use Modules\Loyalty\Application\Query\ExpiringNext;
use Modules\Loyalty\Application\Query\PointsReader;
use Modules\Loyalty\Domain\Exception\PointsAccountNotFound;
use Modules\Loyalty\Domain\Service\PointsArithmetic;
use Modules\Platform\Public\Contracts\PlatformApi;
use Modules\Platform\Public\Contracts\StoreChoices;
use Modules\Platform\Public\Dto\StoreDto;
use Shared\Application\Authorizer;
use Shared\Application\Unauthorized;
use Shared\Domain\ValueObject\Money;
use Shared\Domain\ValueObject\StoreId;

/**
 * One customer's points in every store where the reader holds the points job and the customer has
 * an account (loyalty.md §4.1 L5) - two queries for all of them: the accounts, then their live lots.
 *
 * Only for a customer the reader may see (Access's own rule for G2, asked again here); a store's
 * off state does not hide its points, as it does not
 * hide the customer's addresses there (access.md amendment 58(d)).
 */
final readonly class CustomerPointsInStoresHandler
{
    public const string PERMISSION = LoyaltyPermissions::VIEW;

    public function __construct(
        private Authorizer $authorizer,
        private StaffPointsAction $staff,
        private PointsReader $reader,
        private ProgrammeReader $programmes,
        private PlatformApi $platform,
        private StoreChoices $choices,
    ) {}

    /**
     * @throws PointsAccountNotFound|Unauthorized
     */
    public function handle(CustomerPointsInStores $query): CustomerPoints
    {
        $held = $this->authorizer->storesWith(self::PERMISSION);

        if ($held === []) {
            throw new Unauthorized(self::PERMISSION);
        }

        $customer = $this->staff->existingCustomer($query->customerId);

        // Only for a reader who may see the customer at all, as the page it sits on (access.md
        // amendment 44(c)): a customer of a store they do not see is no customer.
        if (! $this->staff->mayViewCustomer($customer)) {
            throw new PointsAccountNotFound;
        }

        $customerId = strtolower($customer->id);
        // In the stores' own order; null is every store, now and later.
        $stores = array_values(array_filter(
            $this->platform->allStores(),
            static fn (StoreDto $store): bool => $held === null || in_array(strtolower($store->id), array_map(static fn (StoreId $id): string => $id->value, $held), true),
        ));
        $accounts = $this->reader->accountsOf($customerId, array_map(static fn (StoreDto $store): string => strtolower($store->id), $stores));
        $lots = [];

        foreach ($this->reader->liveLotsOf(array_values($accounts), CarbonImmutable::now()) as $lot) {
            $lots[$lot->accountId][] = [$lot->pointsLeft, $lot->expiresAt];
        }

        // What L2's own store filter offers (Platform's StoreChoices): only there may a block link.
        $offered = array_map(static fn (StoreDto $store): string => strtolower($store->id), $this->choices->forJobs(self::PERMISSION));
        $blocks = [];
        $give = null;

        foreach ($stores as $store) {
            $id = strtolower($store->id);
            $account = $accounts[$id] ?? null;
            $mayOpen = in_array($id, $offered, true);

            if ($account === null) {
                // A first point, where the reader may give one and open the page to do it (§9.5 P5).
                if ($give === null && $mayOpen && $this->staff->holds(LoyaltyPermissions::ADJUST, StoreId::fromString($id))) {
                    $give = $store->code;
                }

                continue;
            }

            $programme = $this->programmes->of(StoreId::fromString($id));
            $live = $lots[$account] ?? [];
            $points = array_sum(array_map(static fn (array $lot): int => $lot[0], $live));

            $blocks[] = new StorePoints(
                $id,
                $store->code,
                $store->isActive,
                $points,
                Money::of(PointsArithmetic::worth($points, $programme->pointsPerUnitOff, $programme->exponent), $programme->currencyCode),
                $programme->exponent,
                ExpiringNext::of($live, $store->timezone),
                $mayOpen,
            );
        }

        return new CustomerPoints($blocks, $give);
    }
}
