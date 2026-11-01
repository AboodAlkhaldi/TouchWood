<?php

declare(strict_types=1);

namespace Modules\Sales\Application\Quote;

use Carbon\CarbonImmutable;
use DateTimeImmutable;
use Modules\Catalog\Public\Enums\SaleMode;
use Modules\Pricing\Public\Dto\CartPricesDto;
use Modules\Pricing\Public\Dto\LinePriceDto;
use Modules\Pricing\Public\Dto\TotalsDto;
use Modules\Pricing\Public\Enums\PriceKind;
use Modules\Sales\Public\Enums\OrderAudience;
use Modules\Sales\Public\Enums\WayToPay;
use Shared\Domain\ValueObject\Money;

/**
 * A checkout's quote (sales.md §1.2): **server-computed, itemised, immutable** — the prices of each line,
 * the coupon taken or why not, the points used or why not, the gift or why none, the shipping (the carrier
 * picked and its rule, or the fee to be set by staff — §9.5 #2), the
 * totals, the ways to pay and the points the order would earn — valid until `expiresAt`. Kept whole on
 * the quote's row (`toArray` / `fromArray`), so placing the order reads exactly what was shown, and
 * Promotions re-checks the coupon against the very prices quoted.
 */
final readonly class QuoteView
{
    /** Every moment kept to the microsecond, so what is read back compares equal to what was quoted. */
    private const string MOMENT = 'Y-m-d\TH:i:s.uP';

    /**
     * @param  list<int>  $couponShares  one a priced line, in their order, in minor units
     * @param  list<WayToPay>  $waysToPay
     */
    public function __construct(
        public string $id,
        public string $storeId,
        public string $customerId,
        public string $addressId,
        public OrderAudience $audience,
        public CartPricesDto $prices,
        public ?string $couponCode,
        public ?string $couponId,
        public ?string $couponNameAr,
        public ?string $couponNameEn,
        public Money $couponDiscount,
        public array $couponShares,
        public bool $allowWithPoints,
        public ?string $couponRefusal,
        public bool $usePoints,
        public ?int $pointsAsked,
        public ?string $pointsMode,
        public int $pointsMostUsable,
        public int $pointsUsed,
        public Money $pointsDiscount,
        public ?string $pointsRefusal,
        public ?string $giftLevelId,
        public ?string $giftVariantId,
        public QuoteShipping $shipping,
        public TotalsDto $totals,
        public array $waysToPay,
        public WayToPay $wayToPay,
        public int $earningPreview,
        public DateTimeImmutable $expiresAt,
        public DateTimeImmutable $createdAt,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $money = static fn (Money $amount): int => $amount->minorUnits;
        $moment = static fn (?DateTimeImmutable $at): ?string => $at?->format(self::MOMENT);

        return [
            'audience' => $this->audience->value,
            'prices' => [
                'currency' => $this->prices->currencyCode,
                'taxRate' => $this->prices->taxRateBasisPoints,
                'lines' => array_map(static fn (LinePriceDto $line): array => [
                    'variantId' => $line->variantId, 'mode' => $line->mode->value, 'quantity' => $line->quantity,
                    'listUnit' => $money($line->listUnit), 'unit' => $money($line->unit), 'kind' => $line->kind->value,
                    'listTotal' => $money($line->listTotal), 'total' => $money($line->total), 'endsAt' => $moment($line->endsAt),
                ], $this->prices->lines),
                'gross' => $money($this->prices->grossSubtotal),
                'net' => $money($this->prices->netSubtotal),
                'pricedAt' => $moment($this->prices->pricedAt),
                'validUntil' => $moment($this->prices->validUntil),
            ],
            'coupon' => ['code' => $this->couponCode, 'id' => $this->couponId, 'nameAr' => $this->couponNameAr, 'nameEn' => $this->couponNameEn, 'discount' => $money($this->couponDiscount), 'shares' => $this->couponShares, 'allowWithPoints' => $this->allowWithPoints, 'refusal' => $this->couponRefusal],
            'points' => ['use' => $this->usePoints, 'asked' => $this->pointsAsked, 'mode' => $this->pointsMode, 'mostUsable' => $this->pointsMostUsable, 'used' => $this->pointsUsed, 'discount' => $money($this->pointsDiscount), 'refusal' => $this->pointsRefusal],
            'gift' => ['levelId' => $this->giftLevelId, 'variantId' => $this->giftVariantId],
            'shipping' => $this->shipping->toArray(),
            'totals' => [
                'gross' => $money($this->totals->grossSubtotal), 'net' => $money($this->totals->netSubtotal), 'coupon' => $money($this->totals->couponDiscount),
                'points' => $money($this->totals->pointsDiscount), 'goods' => $money($this->totals->goodsTotal), 'shipping' => $money($this->totals->shipping),
                'taxable' => $money($this->totals->taxableBase), 'vat' => $money($this->totals->vat), 'order' => $money($this->totals->orderTotal),
                'taxRate' => $this->totals->taxRateBasisPoints,
            ],
            'waysToPay' => array_map(static fn (WayToPay $way): string => $way->value, $this->waysToPay),
            'earningPreview' => $this->earningPreview,
        ];
    }

    /**
     * @param  array<string, mixed>  $result  as `toArray` wrote it
     */
    public static function fromArray(string $id, string $storeId, string $customerId, string $addressId, WayToPay $wayToPay, DateTimeImmutable $expiresAt, DateTimeImmutable $createdAt, array $result): self
    {
        /** @var array{audience: string, prices: array{currency: string, taxRate: int, lines: list<array{variantId: string, mode: string, quantity: int, listUnit: int, unit: int, kind: string, listTotal: int, total: int, endsAt: ?string}>, gross: int, net: int, pricedAt: string, validUntil: ?string}, coupon: array{code: ?string, id: ?string, nameAr: ?string, nameEn: ?string, discount: int, shares: list<int>, allowWithPoints: bool, refusal: ?string}, points: array{use: bool, asked: ?int, mode: ?string, mostUsable: int, used: int, discount: int, refusal: ?string}, gift: array{levelId: ?string, variantId: ?string}, shipping: array{coverage: string, carrierId: ?string, carrierAr: ?string, carrierEn: ?string, fee: int, price: int, freeAbove: ?int, freeKind: ?string, carrierChanged: bool, options: list<array{carrierId: string, nameAr: string, nameEn: string, logoUrl: ?string, fee: int, freeBecause: ?string}>, destination: array{countryCode: string, regionId: ?string, cityId: ?string, districtId: ?string}}, totals: array{gross: int, net: int, coupon: int, points: int, goods: int, shipping: int, taxable: int, vat: int, order: int, taxRate: int}, waysToPay: list<string>, earningPreview: int} $result */
        $currency = $result['prices']['currency'];
        $money = static fn (int $minor): Money => Money::of($minor, $currency);
        $moment = static fn (?string $at): ?DateTimeImmutable => $at === null ? null : CarbonImmutable::parse($at);
        $prices = $result['prices'];
        $totals = $result['totals'];

        return new self(
            $id,
            $storeId,
            $customerId,
            $addressId,
            OrderAudience::from($result['audience']),
            new CartPricesDto(
                $storeId,
                $currency,
                $prices['taxRate'],
                array_map(static fn (array $line): LinePriceDto => new LinePriceDto(
                    $line['variantId'], SaleMode::from($line['mode']), $line['quantity'], $money($line['listUnit']), $money($line['unit']),
                    PriceKind::from($line['kind']), $money($line['listTotal']), $money($line['total']), $moment($line['endsAt']),
                ), $prices['lines']),
                [],
                $money($prices['gross']),
                $money($prices['net']),
                CarbonImmutable::parse($prices['pricedAt']),
                $moment($prices['validUntil']),
            ),
            $result['coupon']['code'],
            $result['coupon']['id'],
            $result['coupon']['nameAr'],
            $result['coupon']['nameEn'],
            $money($result['coupon']['discount']),
            $result['coupon']['shares'],
            $result['coupon']['allowWithPoints'],
            $result['coupon']['refusal'],
            $result['points']['use'],
            $result['points']['asked'],
            $result['points']['mode'],
            $result['points']['mostUsable'],
            $result['points']['used'],
            $money($result['points']['discount']),
            $result['points']['refusal'],
            $result['gift']['levelId'],
            $result['gift']['variantId'],
            QuoteShipping::fromArray($result['shipping'], $currency),
            new TotalsDto(
                $money($totals['gross']), $money($totals['net']), $money($totals['coupon']), $money($totals['points']), $money($totals['goods']),
                $money($totals['shipping']), $money($totals['taxable']), $money($totals['vat']), $money($totals['order']), $totals['taxRate'],
            ),
            array_map(static fn (string $way): WayToPay => WayToPay::from($way), $result['waysToPay']),
            $wayToPay,
            $result['earningPreview'],
            $expiresAt,
            $createdAt,
        );
    }
}
