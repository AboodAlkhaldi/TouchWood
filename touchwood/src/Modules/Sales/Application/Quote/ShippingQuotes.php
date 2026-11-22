<?php

declare(strict_types=1);

namespace Modules\Sales\Application\Quote;

use Modules\Access\Public\Dto\AddressDto;
use Modules\Platform\Public\Contracts\PlatformApi;
use Modules\Sales\Domain\Exception\AddressInOtherStore;
use Modules\Sales\Domain\Exception\InvalidSalesAttribute;
use Modules\Shipping\Public\Contracts\ShippingApi;
use Modules\Shipping\Public\Dto\ShippingDestinationDto;
use Modules\Shipping\Public\Dto\ShippingOptionDto;
use Modules\Shipping\Public\Enums\ShippingCoverage;
use Shared\Domain\ValueObject\Money;
use Shared\Domain\ValueObject\StoreId;

/**
 * Sales asking Shipping about an address (sales.md §1.2 step 9, §1.7, §9.5 #2, #12; shipping.md §1.2,
 * §2.1): the address's place — its country and Platform's region, city and district, never its typed
 * text — quoted for the order's goods total. Another store's country is refused, naming it; served, the
 * carrier asked for when it serves the place, else the first offered (the cheapest — §9.3 #2). Reads only.
 */
final readonly class ShippingQuotes
{
    public function __construct(
        private ShippingApi $shipping,
        private PlatformApi $platform,
    ) {}

    /**
     * @return array{countryCode: string, regionId: ?string, cityId: ?string, districtId: ?string}
     */
    public static function destination(AddressDto $address): array
    {
        return ['countryCode' => $address->countryCode, 'regionId' => $address->regionId, 'cityId' => $address->cityId, 'districtId' => $address->districtId];
    }

    /**
     * Whether two places are the same — field by field: a quote and an order keep theirs in jsonb, which does
     * not keep the keys' order.
     *
     * @param  array{countryCode: string, regionId: ?string, cityId: ?string, districtId: ?string}|null  $one
     * @param  array{countryCode: string, regionId: ?string, cityId: ?string, districtId: ?string}|null  $other
     */
    public static function samePlace(?array $one, ?array $other): bool
    {
        if ($one === null || $other === null) {
            return false;
        }

        foreach (['countryCode', 'regionId', 'cityId', 'districtId'] as $key) {
            if ($one[$key] !== $other[$key]) {
                return false;
            }
        }

        return true;
    }

    /**
     * An order's address snapshot (§1.3 step 7) read back as a place — null for one kept before stage 7,
     * which had none.
     *
     * @param  array<string, mixed>  $address
     * @return array{countryCode: string, regionId: ?string, cityId: ?string, districtId: ?string}|null
     */
    public static function placeOf(array $address): ?array
    {
        $country = $address['countryCode'] ?? null;

        if (! is_string($country)) {
            return null;
        }

        $id = static fn (string $key): ?string => is_string($address[$key] ?? null) ? $address[$key] : null;

        return ['countryCode' => $country, 'regionId' => $id('regionId'), 'cityId' => $id('cityId'), 'districtId' => $id('districtId')];
    }

    /**
     * The address quoted: `OTHER_STORE` refused; served, the carrier asked for, else the first. A carrier
     * asked for that does not serve the place is refused when `$strict` (staff choosing it, gap 6.1);
     * otherwise the first is picked and the quote says the carrier changed (gap 6.9).
     *
     * @param  array{countryCode: string, regionId: ?string, cityId: ?string, districtId: ?string}  $destination
     *
     * @throws AddressInOtherStore|InvalidSalesAttribute
     */
    public function quote(StoreId $store, array $destination, Money $goodsTotal, ?string $carrierId = null, bool $strict = false): QuoteShipping
    {
        $answer = $this->shipping->quote($store, new ShippingDestinationDto(
            $destination['countryCode'], $destination['regionId'], $destination['cityId'], $destination['districtId'],
        ), $goodsTotal);
        $zero = Money::zero($goodsTotal->currencyCode);

        if ($answer->coverage === ShippingCoverage::OtherStore) {
            $other = $answer->otherStoreId === null ? null : $this->platform->store(StoreId::fromString($answer->otherStoreId));

            throw new AddressInOtherStore($answer->otherStoreId ?? '', $other->code ?? '', $other->name->ar ?? '', $other->name->en ?? '');
        }

        $options = array_map(static fn (ShippingOptionDto $option): array => [
            'carrierId' => $option->carrierId, 'nameAr' => $option->nameAr, 'nameEn' => $option->nameEn, 'logoUrl' => $option->logoUrl,
            'fee' => $option->fee->minorUnits, 'freeBecause' => $option->freeBecause?->value,
        ], $answer->options);

        if ($answer->coverage !== ShippingCoverage::Served || $answer->options === []) {
            $coverage = $answer->coverage === ShippingCoverage::Outside ? QuoteShipping::OUTSIDE : QuoteShipping::NOT_COVERED;

            if ($strict && $carrierId !== null) {
                throw new InvalidSalesAttribute('carrier', 'none: no carrier serves the new place');
            }

            return new QuoteShipping($coverage, null, null, null, $zero, $zero, null, null, false, [], $destination);
        }

        $asked = $carrierId === null ? null : strtolower(trim($carrierId));
        $picked = null;

        foreach ($answer->options as $option) {
            if ($option->carrierId === $asked) {
                $picked = $option;
            }
        }

        if ($picked === null && $asked !== null && $strict) {
            throw new InvalidSalesAttribute('carrier', 'a carrier serving the new place');
        }

        $chosen = $picked ?? $answer->options[0];

        return new QuoteShipping(
            QuoteShipping::SERVED,
            $chosen->carrierId,
            $chosen->nameAr,
            $chosen->nameEn,
            $chosen->fee,
            $chosen->price ?? $chosen->fee,
            $chosen->freeAbove,
            $chosen->freeAboveBecause?->value,
            $asked !== null && $picked === null,
            $options,
            $destination,
        );
    }
}
