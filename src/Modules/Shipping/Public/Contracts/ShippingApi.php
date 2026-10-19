<?php

declare(strict_types=1);

namespace Modules\Shipping\Public\Contracts;

use Modules\Shipping\Public\Dto\ParcelLineDto;
use Modules\Shipping\Public\Dto\ShippingDestinationDto;
use Modules\Shipping\Public\Dto\ShippingOptionDto;
use Shared\Domain\ValueObject\Money;
use Shared\Domain\ValueObject\StoreId;

/**
 * What Sales asks Shipping (sales.md §2.4) — **declared by Sales for stage 7 to fill, provisional**:
 * the carriers that can take a parcel to a destination, each with its fee (free-shipping thresholds
 * applied, handoff §12.5) and its tracking address. Shipping's own types only (handoff §4.4: Shipping
 * → Platform, Catalog). Not bound until stage 7: Sales uses its flat fee meanwhile (§1.10).
 */
interface ShippingApi
{
    /**
     * @param  list<ParcelLineDto>  $lines
     * @return list<ShippingOptionDto>
     */
    public function options(StoreId $store, ShippingDestinationDto $destination, array $lines, Money $goodsTotal): array;
}
