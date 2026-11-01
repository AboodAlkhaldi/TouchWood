<?php

declare(strict_types=1);

namespace Modules\Sales\Application\Quote;

use Shared\Domain\ValueObject\Money;

/**
 * A quote's shipping (sales.md §1.2 step 9, §9.5 #2; shipping.md §1.2): where the address stands —
 * `SERVED`, `NOT_COVERED` or `OUTSIDE` (`OTHER_STORE` is refused) — and, when served, the carrier picked
 * (the cheapest unless the customer chose another) with its names, the fee the order pays and the rule
 * behind it: the place's price before free shipping, and the free-above amount that applied with which it
 * was (`CARRIER`, `STORE`) — what an edit works the fee out from again (§1.7 step 5). Not served: no
 * carrier, the fee 0 and **to be set** by staff. `carrierChanged`: the carrier asked for no longer
 * serves the place, so the cheapest was picked (gap 6.9). The carriers offered, for the customer's
 * choice, and the place quoted, so placing knows whether the address moved since.
 */
final readonly class QuoteShipping
{
    public const string SERVED = 'SERVED';

    public const string NOT_COVERED = 'NOT_COVERED';

    public const string OUTSIDE = 'OUTSIDE';

    /**
     * @param  list<array{carrierId: string, nameAr: string, nameEn: string, logoUrl: ?string, fee: int, freeBecause: ?string}>  $options
     * @param  array{countryCode: string, regionId: ?string, cityId: ?string, districtId: ?string}  $destination
     */
    public function __construct(
        public string $coverage,
        public ?string $carrierId,
        public ?string $carrierAr,
        public ?string $carrierEn,
        public Money $fee,
        public Money $price,
        public ?Money $freeAbove,
        public ?string $freeKind,
        public bool $carrierChanged,
        public array $options,
        public array $destination,
    ) {}

    /** No carrier serves the place: staff set the fee, and staff are the only way to pay (§9.5 #2). */
    public function pending(): bool
    {
        return $this->coverage !== self::SERVED;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'coverage' => $this->coverage,
            'carrierId' => $this->carrierId,
            'carrierAr' => $this->carrierAr,
            'carrierEn' => $this->carrierEn,
            'fee' => $this->fee->minorUnits,
            'price' => $this->price->minorUnits,
            'freeAbove' => $this->freeAbove?->minorUnits,
            'freeKind' => $this->freeKind,
            'carrierChanged' => $this->carrierChanged,
            'options' => $this->options,
            'destination' => $this->destination,
        ];
    }

    /**
     * @param  array{coverage: string, carrierId: ?string, carrierAr: ?string, carrierEn: ?string, fee: int, price: int, freeAbove: ?int, freeKind: ?string, carrierChanged: bool, options: list<array{carrierId: string, nameAr: string, nameEn: string, logoUrl: ?string, fee: int, freeBecause: ?string}>, destination: array{countryCode: string, regionId: ?string, cityId: ?string, districtId: ?string}}  $shipping  as `toArray` wrote it
     */
    public static function fromArray(array $shipping, string $currency): self
    {
        return new self(
            $shipping['coverage'],
            $shipping['carrierId'],
            $shipping['carrierAr'],
            $shipping['carrierEn'],
            Money::of($shipping['fee'], $currency),
            Money::of($shipping['price'], $currency),
            $shipping['freeAbove'] === null ? null : Money::of($shipping['freeAbove'], $currency),
            $shipping['freeKind'],
            $shipping['carrierChanged'],
            // In the order written: jsonb keeps an object's keys in an order of its own.
            array_map(static fn (array $option): array => [
                'carrierId' => $option['carrierId'], 'nameAr' => $option['nameAr'], 'nameEn' => $option['nameEn'], 'logoUrl' => $option['logoUrl'],
                'fee' => $option['fee'], 'freeBecause' => $option['freeBecause'],
            ], $shipping['options']),
            [
                'countryCode' => $shipping['destination']['countryCode'], 'regionId' => $shipping['destination']['regionId'],
                'cityId' => $shipping['destination']['cityId'], 'districtId' => $shipping['destination']['districtId'],
            ],
        );
    }
}
