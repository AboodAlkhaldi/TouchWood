<?php

declare(strict_types=1);

namespace Modules\Pricing\Application\Query\OverlapsFor;

use Carbon\CarbonImmutable;
use DateTimeInterface;
use InvalidArgumentException;
use Modules\Platform\Public\Contracts\PlatformApi;
use Modules\Pricing\Application\Prices\SaleInput;
use Modules\Pricing\Application\Prices\SaleSizes;
use Modules\Pricing\Application\PricingPermissions;
use Modules\Pricing\Domain\Exception\InvalidPricingInput;
use Modules\Pricing\Domain\Repository\Sales;
use Shared\Application\Authorizer;
use Shared\Application\Unauthorized;
use Shared\Domain\ValueObject\StoreId;

/**
 * **The note before saving** (`OverlapsFor`, pricing.md §1.3, §3), for whoever holds any of Pricing's
 * three jobs in that store (owner, 2026-10-08). The sizes are read as adding the sale reads them
 * (`SaleSizes`), its start now when empty or past; every sale not ended on those sizes whose dates meet
 * it is named, the sale itself left out when it is being changed. A read: no lock, nothing written.
 */
final readonly class OverlapsForHandler
{
    /** Any of the three, in that store. */
    public const array PERMISSIONS = [
        PricingPermissions::PRICE_EDIT,
        PricingPermissions::WHOLESALE_EDIT,
        PricingPermissions::CATEGORY_DISCOUNT_MANAGE,
    ];

    public function __construct(
        private Authorizer $authorizer,
        private PlatformApi $platform,
        private SaleSizes $sizes,
        private Sales $sales,
    ) {}

    /**
     * @throws Unauthorized|InvalidPricingInput
     */
    public function handle(OverlapsFor $query): OverlapNote
    {
        $store = $this->storeFor($query->storeId);
        [$productId, $variantIds] = $this->sizes->of($query->productId, $query->variantIds);
        $now = CarbonImmutable::now()->toDateTimeImmutable();
        $startsAt = SaleInput::start($query->startsAt, $now);
        $endsAt = SaleInput::end($query->endsAt);
        $overlapping = [];
        // Ids are kept in lower case, as the sale's own handlers read them.
        $itself = $query->saleId === null ? null : strtolower(trim($query->saleId));

        foreach ($this->sales->notEndedOn($store, $variantIds, $now) as $sale) {
            if ($sale->id !== $itself && $sale->overlaps($startsAt, $endsAt)) {
                $overlapping[] = new OverlappingSale(
                    $sale->id,
                    $sale->percentBasisPoints,
                    $sale->price?->minorUnits,
                    $sale->price?->currencyCode,
                    $sale->startsAt->format(DateTimeInterface::ATOM),
                    $sale->endsAt?->format(DateTimeInterface::ATOM),
                    $sale->alwaysWins,
                    array_values(array_intersect($sale->variantIds, $variantIds)),
                );
            }
        }

        return new OverlapNote($productId, $variantIds, $overlapping);
    }

    /**
     * @throws Unauthorized
     */
    private function storeFor(string $storeId): StoreId
    {
        try {
            $store = StoreId::fromString($storeId);
        } catch (InvalidArgumentException) {
            throw new Unauthorized(self::PERMISSIONS[0]);
        }

        foreach (self::PERMISSIONS as $permission) {
            $stores = $this->authorizer->storesWith($permission);

            if ($stores === null || in_array($store->value, array_map(static fn (StoreId $id): string => $id->value, $stores), true)) {
                // A store that does not exist is invalid input, as saving a sale finds it (owner,
                // 2026-10-10) - asked only of a reader allowed there, so it tells no one else anything.
                return $this->platform->store($store) === null ? throw new InvalidPricingInput('store') : $store;
            }
        }

        throw new Unauthorized(self::PERMISSIONS[0]);
    }
}
