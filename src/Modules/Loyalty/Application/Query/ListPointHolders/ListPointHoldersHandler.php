<?php

declare(strict_types=1);

namespace Modules\Loyalty\Application\Query\ListPointHolders;

use Carbon\CarbonImmutable;
use Modules\Access\Public\Contracts\AccessApi;
use Modules\Loyalty\Application\LoyaltyPermissions;
use Modules\Loyalty\Application\Points\Inputs;
use Modules\Loyalty\Application\Points\ProgrammeReader;
use Modules\Loyalty\Application\Points\StaffPointsAction;
use Modules\Loyalty\Application\Query\CustomerName;
use Modules\Loyalty\Application\Query\ExpiringNext;
use Modules\Loyalty\Application\Query\HolderRow;
use Modules\Loyalty\Application\Query\PointsReader;
use Modules\Loyalty\Application\Query\PointsViews;
use Modules\Loyalty\Domain\Exception\InvalidPointsAttribute;
use Modules\Loyalty\Domain\Exception\PointsAccountNotFound;
use Modules\Loyalty\Domain\Service\PointsArithmetic;
use Modules\Platform\Public\Contracts\PlatformApi;
use Shared\Application\Authorizer;
use Shared\Application\Unauthorized;
use Shared\Domain\ValueObject\Money;
use Shared\Domain\ValueObject\StoreId;
use Shared\Infrastructure\Persistence\Ulids;

/**
 * The store's points holders (loyalty.md §4.1 L1), read in a fixed few queries whatever the page:
 * the accounts with their live points, their live lots, and their customers' names from Access.
 *
 * A search asks Access for the customers matching it - names in any store, emails and phones only
 * among the customers the reader may view (access.md amendment 72(b)) - and keeps those with points
 * here. Access answers at most 1,000: at that many, the search says to type more rather than show a
 * list that would look complete (§9.5 P2).
 */
final readonly class ListPointHoldersHandler
{
    public const string PERMISSION = LoyaltyPermissions::VIEW;

    /** Access's most for one search: reaching it means there may be more. */
    public const int SEARCH_MOST = 1000;

    public function __construct(
        private Authorizer $authorizer,
        private StaffPointsAction $staff,
        private PointsReader $reader,
        private ProgrammeReader $programmes,
        private PlatformApi $platform,
        private AccessApi $access,
    ) {}

    /**
     * @throws InvalidPointsAttribute|PointsAccountNotFound|Unauthorized
     */
    public function handle(ListPointHolders $query): PointHoldersPage
    {
        $store = Inputs::store($query->storeId);
        // A store the reader does not cover reads as no points there (§7), as the redemptions do.
        $this->authorizer->authorize(self::PERMISSION, $this->staff->scope(self::PERMISSION, $store));
        $perPage = min(max($query->perPage, 1), PointsViews::PER_PAGE);
        $after = self::after($query->afterPoints, $query->afterAccountId);
        $customerIds = null;

        if ($query->search !== null) {
            $customerIds = $this->access->customerIdsMatching(self::search($query->search), self::SEARCH_MOST);

            if (count($customerIds) >= self::SEARCH_MOST) {
                return new PointHoldersPage([], null, null, true);
            }
        }

        $now = CarbonImmutable::now();
        // One more than a page, to learn whether there is another without counting.
        $rows = $this->reader->holders($store->value, $now, $customerIds, $after, $perPage + 1);
        $more = count($rows) > $perPage;
        $rows = array_slice($rows, 0, $perPage);
        $last = $more ? end($rows) : false;

        return new PointHoldersPage(
            $this->holders($rows, $store, $now),
            $last === false ? null : $last->livePoints,
            $last === false ? null : $last->accountId,
        );
    }

    /**
     * @param  list<HolderRow>  $rows
     * @return list<PointHolder>
     */
    private function holders(array $rows, StoreId $store, CarbonImmutable $now): array
    {
        if ($rows === []) {
            return [];
        }

        $programme = $this->programmes->of($store);
        $timezone = $this->platform->store($store)->timezone ?? 'UTC';
        $customers = $this->access->customers(array_map(static fn (HolderRow $row): string => $row->customerId, $rows));
        $viewStores = $this->access->customerViewStores();
        $lots = [];

        foreach ($this->reader->liveLotsOf(array_map(static fn (HolderRow $row): string => $row->accountId, $rows), $now) as $lot) {
            $lots[$lot->accountId][] = [$lot->pointsLeft, $lot->expiresAt];
        }

        return array_map(function (HolderRow $row) use ($programme, $timezone, $customers, $viewStores, $lots): PointHolder {
            $customer = $customers[$row->customerId] ?? null;
            $mayView = $customer !== null && ($viewStores === null || in_array(strtolower($customer->homeStoreId), array_map(static fn (StoreId $held): string => $held->value, $viewStores), true));

            return new PointHolder(
                $row->accountId,
                CustomerName::of($row->customerId, $customer, $mayView),
                $row->livePoints,
                Money::of(PointsArithmetic::worth($row->livePoints, $programme->pointsPerUnitOff, $programme->exponent), $programme->currencyCode),
                ExpiringNext::of($lots[$row->accountId] ?? [], $timezone),
                $row->lastAt,
            );
        }, $rows);
    }

    /**
     * @throws InvalidPointsAttribute
     */
    private static function search(string $search): string
    {
        $search = trim($search);
        $length = mb_strlen($search);

        if ($length < 3 || $length > 100 || ! mb_check_encoding($search, 'UTF-8') || preg_match('/\p{Cc}/u', $search) === 1) {
            throw new InvalidPointsAttribute('search', '3 to 100 characters of plain text');
        }

        return $search;
    }

    /**
     * Both halves of the cursor, or neither.
     *
     * @return array{0: int, 1: string}|null
     *
     * @throws InvalidPointsAttribute
     */
    private static function after(?int $points, ?string $accountId): ?array
    {
        if ($points === null && $accountId === null) {
            return null;
        }

        if ($points === null || $points < 0 || $accountId === null || ! Ulids::valid($accountId)) {
            throw new InvalidPointsAttribute('after', 'the last row\'s points and account');
        }

        return [$points, strtolower($accountId)];
    }
}
