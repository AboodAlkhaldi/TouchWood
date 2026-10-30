<?php

declare(strict_types=1);

namespace Modules\Shipping\Application;

use Modules\Platform\Public\Contracts\PlatformApi;
use Modules\Platform\Public\Dto\MediaUrlsDto;
use Modules\Platform\Public\Dto\PlaceDto;
use Modules\Platform\Public\Enums\ImageFormat;
use Modules\Platform\Public\Enums\MediaSize;
use Modules\Platform\Public\Enums\PlaceKind;
use Modules\Shipping\Application\Box\BoxSuggester;
use Modules\Shipping\Application\Price\PriceReader;
use Modules\Shipping\Domain\Model\Carrier;
use Modules\Shipping\Domain\Repository\CarrierRepository;
use Modules\Shipping\Public\Contracts\ShippingApi;
use Modules\Shipping\Public\Dto\BoxSuggestionDto;
use Modules\Shipping\Public\Dto\CarrierDto;
use Modules\Shipping\Public\Dto\ShippingDestinationDto;
use Modules\Shipping\Public\Dto\ShippingOptionDto;
use Modules\Shipping\Public\Dto\ShippingQuoteDto;
use Modules\Shipping\Public\Enums\FreeShippingReason;
use Modules\Shipping\Public\Enums\ShippingCoverage;
use Shared\Domain\ValueObject\Money;
use Shared\Domain\ValueObject\StoreId;
use Shared\Infrastructure\Persistence\Ulids;

/**
 * Shipping's answers to Sales (shipping.md §2.1).
 */
final readonly class ShippingApiImpl implements ShippingApi
{
    public function __construct(
        private PlatformApi $platform,
        private CarrierRepository $carriers,
        private PriceReader $prices,
        private BoxSuggester $boxes,
    ) {}

    /**
     * The country first (§1.2): no store of ours ships there → OUTSIDE; another store does →
     * OTHER_STORE. Then the place: each switched-on carrier of the store in that country charges its
     * price on the most specific place it prices - the district, else the city, else the region (rule 5;
     * owner, 2026-10-29) - free shipping applied (§1.5); none → NOT_COVERED.
     */
    public function quote(StoreId $store, ShippingDestinationDto $to, Money $goodsTotal): ShippingQuoteDto
    {
        $countryCode = strtoupper(trim($to->countryCode));
        $owner = $this->platform->countryStore($countryCode);

        if ($owner === null) {
            return new ShippingQuoteDto(ShippingCoverage::Outside);
        }

        if ($owner->id !== $store->value) {
            return new ShippingQuoteDto(ShippingCoverage::OtherStore, otherStoreId: $owner->id);
        }

        $chain = $this->chain($to, $countryCode);
        $best = [];

        foreach ($this->prices->onPlaces($store->value, $countryCode, $chain) as $price) {
            $rank = array_search($price['placeId'], $chain, true);

            if (! isset($best[$price['carrierId']]) || $rank < $best[$price['carrierId']]['rank']) {
                $best[$price['carrierId']] = ['rank' => $rank, 'amountMinor' => $price['amountMinor']];
            }
        }

        $carriers = [];

        foreach ($best === [] ? [] : $this->carriers->inStore($store->value) as $carrier) {
            if (isset($best[$carrier->id]) && $carrier->isActive()) {
                $carriers[] = $carrier;
            }
        }

        // Decided on the carriers read last: one switched off between the two reads is in neither.
        if ($carriers === []) {
            return new ShippingQuoteDto(ShippingCoverage::NotCovered);
        }

        $storeWide = $this->platform->setting(ShippingSettings::FREE_ABOVE_ALL, $store)->int();

        $logos = $this->logos($carriers);
        $options = [];

        foreach ($carriers as $carrier) {
            [$fee, $because] = self::fee($best[$carrier->id]['amountMinor'], $carrier, $storeWide, $goodsTotal);
            $options[] = ['position' => $carrier->position, 'option' => new ShippingOptionDto(
                $carrier->id,
                $carrier->details()->nameAr,
                $carrier->details()->nameEn,
                $logos[$carrier->id] ?? null,
                $fee,
                $because,
            )];
        }

        usort($options, static fn (array $a, array $b): int => [$a['option']->fee->minorUnits, $a['position'], $a['option']->carrierId]
            <=> [$b['option']->fee->minorUnits, $b['position'], $b['option']->carrierId]);

        return new ShippingQuoteDto(ShippingCoverage::Served, array_map(static fn (array $option): ShippingOptionDto => $option['option'], $options));
    }

    public function carrier(string $carrierId): ?CarrierDto
    {
        $id = strtolower(trim($carrierId));

        if (! Ulids::valid($id)) {
            return null;
        }

        $carrier = $this->carriers->find($id);

        return $carrier === null ? null : $this->dtos([$carrier])[0];
    }

    public function carriers(StoreId $store): array
    {
        $carriers = $this->carriers->inStore($store->value);
        // Switched-on first, each keeping its country's order (§9.1 S17).
        usort($carriers, static fn (Carrier $a, Carrier $b): int => $b->isActive() <=> $a->isActive());

        return $this->dtos($carriers);
    }

    /**
     * The pattern's `{number}` replaced by the number, its outer spaces trimmed and encoded for an
     * address (§8 #7). No pattern, an empty number or a carrier gone → null.
     */
    public function trackingLink(string $carrierId, string $trackingNumber): ?string
    {
        $number = trim($trackingNumber);
        $id = strtolower(trim($carrierId));

        if ($number === '' || ! Ulids::valid($id)) {
            return null;
        }

        $pattern = $this->carriers->find($id)?->details()->trackingPattern;

        return $pattern === null ? null : str_replace('{number}', rawurlencode($number), $pattern);
    }

    /**
     * The places a price may be on for this address, most specific first: read from Platform from the
     * most specific id given, so its city and region are Platform's own, never the caller's. A place of
     * another country counts for nothing. A typed city or district has no id: the region's price
     * serves it (owner, 2026-10-29, gap 4.1).
     *
     * @return list<string>
     */
    private function chain(ShippingDestinationDto $to, string $countryCode): array
    {
        $ids = array_values(array_filter(
            array_map(static fn (?string $id): ?string => $id === null ? null : strtolower(trim($id)), [$to->districtId, $to->cityId, $to->regionId]),
            static fn (?string $id): bool => $id !== null && Ulids::valid($id),
        ));

        if ($ids === []) {
            return [];
        }

        $place = $this->platform->place([$ids[0]])[$ids[0]] ?? null;

        if (! $place instanceof PlaceDto || $place->countryCode !== $countryCode) {
            return [];
        }

        return array_values(array_filter(match ($place->kind) {
            PlaceKind::District => [$place->id, $place->cityId, $place->regionId],
            PlaceKind::City => [$place->id, $place->regionId],
            PlaceKind::Region => [$place->id],
        }, static fn (?string $id): bool => $id !== null));
    }

    /**
     * The store-wide amount, while set, overrides every carrier's own (§1.5); the goods total reaching
     * it - equal included - makes the fee 0. A price that is 0 already is not "free because".
     *
     * @return array{Money, FreeShippingReason|null}
     */
    private static function fee(int $amountMinor, Carrier $carrier, int $storeWide, Money $goodsTotal): array
    {
        $currency = $goodsTotal->currencyCode;
        [$threshold, $reason] = $storeWide > 0
            ? [$storeWide, FreeShippingReason::Store]
            : [$carrier->freeAboveMinor(), FreeShippingReason::Carrier];

        if ($amountMinor > 0 && $threshold !== null && $goodsTotal->minorUnits >= $threshold) {
            return [Money::zero($currency), $reason];
        }

        return [Money::of($amountMinor, $currency), null];
    }

    /**
     * @param  list<Carrier>  $carriers
     * @return list<CarrierDto>
     */
    private function dtos(array $carriers): array
    {
        $logos = $this->logos($carriers);

        return array_map(static fn (Carrier $carrier): CarrierDto => new CarrierDto(
            $carrier->id,
            $carrier->storeId,
            $carrier->countryCode,
            $carrier->details()->nameAr,
            $carrier->details()->nameEn,
            $logos[$carrier->id] ?? null,
            $carrier->details()->phone,
            $carrier->details()->website,
            $carrier->details()->trackingPattern !== null,
            $carrier->isActive(),
        ), $carriers);
    }

    /**
     * The carriers' logos, read together (one Platform read): the thumbnail, best format first; a logo
     * whose sizes are not ready yet has none.
     *
     * @param  list<Carrier>  $carriers
     * @return array<string, string> carrier id => logo address
     */
    private function logos(array $carriers): array
    {
        $mediaIds = array_values(array_unique(array_filter(array_map(static fn (Carrier $carrier): ?string => $carrier->logoMediaId(), $carriers))));

        if ($mediaIds === []) {
            return [];
        }

        $urls = $this->platform->mediaUrlsOf($mediaIds);
        $logos = [];

        foreach ($carriers as $carrier) {
            $media = $carrier->logoMediaId() === null ? null : ($urls[$carrier->logoMediaId()] ?? null);
            $url = $media === null ? null : self::thumb($media);

            if ($url !== null) {
                $logos[$carrier->id] = $url;
            }
        }

        return $logos;
    }

    private static function thumb(MediaUrlsDto $urls): ?string
    {
        $sizes = $urls->variants[MediaSize::Thumb->slug()] ?? [];

        foreach ([ImageFormat::Avif, ImageFormat::Webp, ImageFormat::Jpeg] as $format) {
            $url = $sizes[$format->extension()] ?? null;

            if (is_string($url)) {
                return $url;
            }
        }

        return null;
    }

    public function suggestBox(StoreId $store, array $lines): ?BoxSuggestionDto
    {
        return $this->boxes->suggest($store, $lines);
    }
}
