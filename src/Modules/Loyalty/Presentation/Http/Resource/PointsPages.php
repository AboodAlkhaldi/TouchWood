<?php

declare(strict_types=1);

namespace Modules\Loyalty\Presentation\Http\Resource;

use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Contracts\Foundation\Application;
use Modules\Access\Public\Contracts\AccessApi;
use Modules\Loyalty\Application\LoyaltyPermissions;
use Modules\Loyalty\Application\Points\Inputs;
use Modules\Loyalty\Application\Points\ProgrammeReader;
use Modules\Loyalty\Application\Query\CustomerName;
use Modules\Loyalty\Application\Query\ExpiringNext;
use Modules\Loyalty\Application\Query\ListPointHolders\ListPointHolders;
use Modules\Loyalty\Application\Query\ListPointHolders\ListPointHoldersHandler;
use Modules\Loyalty\Application\Query\ListPointHolders\PointHolder;
use Modules\Loyalty\Application\Query\ListRedemptions\ListRedemptions;
use Modules\Loyalty\Application\Query\ListRedemptions\ListRedemptionsHandler;
use Modules\Loyalty\Application\Query\LotView;
use Modules\Loyalty\Application\Query\LoyaltyActions\LoyaltyActionsForReader;
use Modules\Loyalty\Application\Query\PointsHistoryRow;
use Modules\Loyalty\Application\Query\PointsView;
use Modules\Loyalty\Application\Query\RedemptionRow;
use Modules\Loyalty\Application\Query\ViewCustomerPoints\ViewCustomerPoints;
use Modules\Loyalty\Application\Query\ViewCustomerPoints\ViewCustomerPointsHandler;
use Modules\Loyalty\Application\Query\ViewMyPoints\ViewMyPoints;
use Modules\Loyalty\Application\Query\ViewMyPoints\ViewMyPointsHandler;
use Modules\Loyalty\Domain\Exception\InvalidPointsAttribute;
use Modules\Loyalty\Domain\Exception\PointsAccountNotFound;
use Modules\Platform\Public\Contracts\PlatformApi;
use Modules\Platform\Public\Contracts\StoreChoices;
use Modules\Platform\Public\Dto\StoreDto;
use Shared\Application\StoreContext;
use Shared\Application\Unauthorized;
use Shared\Domain\ValueObject\Money;

/**
 * Loyalty's screens' data (loyalty.md §4.1), built from the module's Application queries - which
 * authorize - and nothing else of its own deciding: what a reader may do comes from
 * LoyaltyActionsForReader, never from here.
 */
final readonly class PointsPages
{
    private const string CURSOR = 'Y-m-d\TH:i:s.uP';

    public function __construct(
        private Application $app,
        private StoreChoices $choices,
        private PlatformApi $platform,
        private ProgrammeReader $programmes,
        private AccessApi $access,
    ) {}

    /**
     * L1.
     *
     * @throws InvalidPointsAttribute|Unauthorized
     */
    public function holders(?string $storeCode, ?string $search, ?int $afterPoints, ?string $afterAccountId, ListPointHoldersHandler $list): PointHoldersPage
    {
        [$store, $offered] = $this->store($storeCode);
        $page = $list->handle(new ListPointHolders($store->id, $search, $afterPoints, $afterAccountId));
        $exponent = $this->programmes->of($store->storeId())->exponent;

        return new PointHoldersPage(
            array_map(fn (PointHolder $holder): PointHolderData => new PointHolderData(
                $holder->accountId,
                CustomerNameData::of($holder->customer),
                $holder->livePoints,
                MoneyData::of($holder->worth, $exponent),
                ExpiringData::of($holder->expiringNext),
                $holder->lastAt === null ? null : self::moment($holder->lastAt),
            ), $page->rows),
            $page->nextPoints,
            $page->nextAccountId,
            $page->tooMany,
            $search,
            ...$this->shown($store),
            stores: $this->options($offered),
        );
    }

    /**
     * L2.
     *
     * @throws InvalidPointsAttribute|Unauthorized|PointsAccountNotFound
     */
    public function customer(string $customerId, ?string $storeCode, ?string $beforeAt, ?int $beforeId, ViewCustomerPointsHandler $view, LoyaltyActionsForReader $actions): CustomerPointsPage
    {
        [$store, $offered] = $this->store($storeCode);
        $points = $view->handle(new ViewCustomerPoints($customerId, $store->id, self::before($beforeAt), $beforeId));
        $may = $actions->forCustomer($customerId, $store->storeId());
        $programme = $this->programmes->of($store->storeId());

        return new CustomerPointsPage(
            CustomerNameData::of($may->customer),
            $points->balance,
            MoneyData::of($points->worth, $programme->exponent),
            ExpiringData::of(self::expiring($points, $store->timezone)),
            self::lots($points),
            array_map(self::row(...), $points->history),
            $points->nextAt === null ? null : self::cursor($points->nextAt),
            $points->nextId,
            $may->mayChangePoints,
            $may->mayOpenCustomer,
            Inputs::MOST_BY_HAND,
            ...$this->shown($store),
            stores: $this->options($offered),
            held: $points->held,
        );
    }

    /**
     * L4.
     *
     * @throws InvalidPointsAttribute|Unauthorized|PointsAccountNotFound
     */
    public function redemptions(?string $storeCode, ?string $beforeAt, ?int $beforeId, ListRedemptionsHandler $list): RedemptionsListPage
    {
        [$store, $offered] = $this->store($storeCode);
        $page = $list->handle(new ListRedemptions($store->id, self::before($beforeAt), $beforeId));
        // Each amount with its own currency's decimals: an order keeps the currency it was placed in.
        $exponents = [];
        $exponent = function (string $code) use (&$exponents, $store): int {
            return $exponents[$code] ??= $this->platform->currency($code)->exponent ?? $this->programmes->of($store->storeId())->exponent;
        };
        // The names in one read (access.md amendment 70), the name only (§9.5 P1).
        $customers = $page->rows === [] ? [] : $this->access->customers(array_values(array_unique(array_map(static fn (RedemptionRow $row): string => $row->customerId, $page->rows))));

        return new RedemptionsListPage(
            array_map(static fn (RedemptionRow $row): RedemptionRowData => new RedemptionRowData(
                $row->id,
                $row->orderNumber,
                CustomerNameData::of(CustomerName::of($row->customerId, $customers[$row->customerId] ?? null, false)),
                $row->points,
                MoneyData::of($row->discount, $exponent($row->discount->currencyCode)),
                self::moment($row->occurredAt),
            ), $page->rows),
            $page->nextAt === null ? null : self::cursor($page->nextAt),
            $page->nextId,
            ...$this->shown($store),
            stores: $this->options($offered),
        );
    }

    /**
     * L7, in the shop's store.
     *
     * @throws InvalidPointsAttribute|Unauthorized
     */
    public function mine(StoreContext $context, ?string $beforeAt, ?int $beforeId, ViewMyPointsHandler $view): MyPointsPage
    {
        $storeId = $context->current();
        $store = $this->platform->store($storeId) ?? throw new InvalidPointsAttribute('store', 'no such store');
        $points = $view->handle(new ViewMyPoints($storeId->value, self::before($beforeAt), $beforeId));
        $programme = $this->programmes->of($storeId);

        return new MyPointsPage(
            $points->balance,
            MoneyData::of($points->worth, $programme->exponent),
            ExpiringData::of(self::expiring($points, $store->timezone)),
            self::lots($points),
            array_map(self::row(...), $points->history),
            $points->nextAt === null ? null : self::cursor($points->nextAt),
            $points->nextId,
            $programme->enabled,
            $programme->earnPerUnit,
            $programme->pointsPerUnitOff,
            // 1 of the store's currency, as the line says it ("for every 1.00 SAR").
            MoneyData::of(Money::of(10 ** $programme->exponent, $programme->currencyCode), $programme->exponent),
            $store->timezone,
            held: $points->held,
        );
    }

    /**
     * The store a staff screen shows, and those its filter offers: the one asked for by its code
     * among them, else the first that is on (StoreChoices); none at all is a refusal.
     *
     * @return array{0: StoreDto, 1: list<StoreDto>}
     *
     * @throws Unauthorized
     */
    private function store(?string $code): array
    {
        $offered = $this->choices->forJobs(LoyaltyPermissions::VIEW);
        $chosen = null;

        if ($code === null) {
            foreach ($offered as $store) {
                if ($store->isActive) {
                    $chosen = $store;
                    break;
                }
            }

            // A Super Admin with every store off still opens one.
            $chosen ??= $offered[0] ?? null;
        } else {
            foreach ($offered as $store) {
                if (strtolower($store->code) === strtolower(trim($code))) {
                    $chosen = $store;
                }
            }
        }

        // Another store, one that does not exist, or none to offer: the same refusal, so a filter
        // tells nobody which stores there are.
        if ($chosen === null) {
            throw new Unauthorized(LoyaltyPermissions::VIEW);
        }

        return [$chosen, $offered];
    }

    /**
     * @return array{storeCode: string, storeName: string, storeIsActive: bool, storeTimezone: string, programmeOn: bool}
     */
    private function shown(StoreDto $store): array
    {
        return [
            'storeCode' => $store->code,
            'storeName' => $store->name->in($this->locale()),
            'storeIsActive' => $store->isActive,
            'storeTimezone' => $store->timezone,
            'programmeOn' => $this->programmes->of($store->storeId())->enabled,
        ];
    }

    /**
     * @param  list<StoreDto>  $stores
     * @return list<StoreOptionData>
     */
    private function options(array $stores): array
    {
        return array_map(fn (StoreDto $store): StoreOptionData => new StoreOptionData($store->code, $store->name->in($this->locale()), $store->isActive), $stores);
    }

    /**
     * @return list<LotData>
     */
    private static function lots(PointsView $points): array
    {
        return array_map(static fn (LotView $lot): LotData => new LotData($lot->pointsLeft, self::moment($lot->expiresAt)), $points->lots);
    }

    private static function expiring(PointsView $points, string $timezone): ?ExpiringNext
    {
        return ExpiringNext::of(array_map(static fn (LotView $lot): array => [$lot->pointsLeft, $lot->expiresAt], $points->lots), $timezone);
    }

    private static function row(PointsHistoryRow $row): HistoryRowData
    {
        return new HistoryRowData(
            $row->id,
            $row->kind->value,
            $row->points,
            self::moment($row->occurredAt),
            $row->orderNumber,
            $row->staffName,
            $row->reason,
        );
    }

    /** A moment as the screens take it: ISO 8601 in UTC, to the millisecond, written in the store's zone by `Time`. */
    private static function moment(DateTimeImmutable $at): string
    {
        return $at->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d\TH:i:s.vP');
    }

    /**
     * A next-page cursor's moment, to the microsecond the ledger keeps: a coarser one would skip or
     * repeat the rows of one second at a page's edge.
     */
    private static function cursor(DateTimeImmutable $at): string
    {
        return $at->setTimezone(new DateTimeZone('UTC'))->format(self::CURSOR);
    }

    /**
     * A cursor's moment from the address; one that is not a moment is the page's own refusal.
     *
     * @throws InvalidPointsAttribute
     */
    private static function before(?string $at): ?DateTimeImmutable
    {
        if ($at === null) {
            return null;
        }

        $moment = DateTimeImmutable::createFromFormat(self::CURSOR, $at);

        return $moment === false ? throw new InvalidPointsAttribute('before', 'a moment') : $moment;
    }

    private function locale(): string
    {
        return $this->app->getLocale() === 'en' ? 'en' : 'ar';
    }
}
