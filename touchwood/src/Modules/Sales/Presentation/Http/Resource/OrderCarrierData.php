<?php

declare(strict_types=1);

namespace Modules\Sales\Presentation\Http\Resource;

use Modules\Shipping\Public\Dto\CarrierDto;
use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/** One of the store's carriers for Ship… (sales.md §9.5 #3; gap 6.3): the order's country first, switched-on first; whether its pattern makes a Track link from the number. */
#[TypeScript]
final class OrderCarrierData extends Data
{
    public function __construct(
        public string $id,
        public string $name,
        public string $countryCode,
        public bool $active,
        public bool $hasTrackingPattern,
        /** Of the order's country: the others say theirs in the list (sales.md §4.3). */
        public bool $inOrderCountry = true,
    ) {}

    /**
     * @param  list<CarrierDto>  $carriers
     * @return list<self>
     */
    public static function list(array $carriers, ?string $country, bool $english): array
    {
        return array_map(static fn (CarrierDto $carrier): self => new self(
            $carrier->id, $english ? $carrier->nameEn : $carrier->nameAr, $carrier->countryCode, $carrier->active, $carrier->hasTrackingPattern,
            $carrier->countryCode === ($country === null ? null : strtoupper($country)),
        ), $carriers);
    }
}
