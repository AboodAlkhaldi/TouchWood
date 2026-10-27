<?php

declare(strict_types=1);

namespace Modules\Sales\Presentation\Http\Resource;

use InvalidArgumentException;
use Modules\Catalog\Public\Contracts\ShopBestSellers;
use Modules\Platform\Public\Contracts\PlatformApi;
use Modules\Platform\Public\Dto\MoneyData;
use Modules\Platform\Public\Dto\StoreDto;
use Modules\Sales\Application\Query\ViewCart\CartLineView;
use Modules\Sales\Application\Query\ViewCart\CartNoticeLine;
use Modules\Sales\Application\Query\ViewCart\ViewCart;
use Modules\Sales\Application\Query\ViewCart\ViewCartHandler;
use Shared\Application\Unauthorized;
use Shared\Domain\ValueObject\Money;
use Shared\Domain\ValueObject\StoreId;

/**
 * S4 - the shop's cart (sales.md §4.1), built from §3.1's `ViewCart`: every amount written by the server in the
 * store's own currency and decimals (Platform's `MoneyData`), the sizes' values in the page's language. Under an
 * empty cart, the store's best sellers (B14; R2-9).
 */
final readonly class CartPages
{
    /** The best sellers under an empty cart: a row of cards. */
    private const int BEST_SELLERS = 8;

    public function __construct(
        private PlatformApi $platform,
        private ShopBestSellers $bestSellers,
    ) {}

    /**
     * @param  list<array<string, mixed>>  $skipped  Buy Again's, as it left them
     *
     * @throws Unauthorized
     */
    public function cart(string $storeId, string $locale, ViewCartHandler $view, array $skipped = []): CartPage
    {
        $locale = $locale === 'en' ? 'en' : 'ar';
        $cart = $view->handle(new ViewCart($storeId, $locale));
        $store = $this->platform->store(StoreId::fromString($cart->storeId));
        $money = fn (?Money $amount): ?MoneyData => $amount === null ? null : self::money($amount, $store, $cart->exponent, $locale);
        // Free shipping measured on the goods (B12): what is missing, and the share for the bar.
        $goods = $cart->goodsTotal->minorUnits ?? 0;
        $free = $cart->freeAbove?->minorUnits;
        $missing = $free !== null && $cart->goodsTotal !== null && $goods < $free ? Money::of($free - $goods, $cart->freeAbove->currencyCode) : null;
        $best = $cart->lines === [] ? $this->bestSellers->read(StoreId::fromString($cart->storeId), $locale, self::BEST_SELLERS) : ['cards' => [], 'cardFacts' => []];

        return new CartPage(
            array_map(static fn (CartLineView $line): CartLineData => new CartLineData(
                $line->variantId,
                $line->name,
                $line->slug,
                $line->photo,
                $line->code,
                self::values($line->values, $locale),
                $line->mode,
                $line->quantity,
                $money($line->unit),
                $line->unit !== null && $line->listUnit !== null && $line->listUnit->minorUnits > $line->unit->minorUnits ? $money($line->listUnit) : null,
                $money($line->total),
                $line->problem?->value,
                $line->minimum,
                $line->maximum,
            ), $cart->lines),
            $cart->mayOrder,
            $cart->couponCode,
            array_map(static fn (CartNoticeLine $line): CartNoticeData => new CartNoticeData(
                $line->variantId, $line->name, self::values($line->values, $locale), $line->mode, $line->quantity, $line->reason->value,
            ), $cart->notice),
            $money($cart->subtotal),
            array_sum(array_map(static fn (CartLineView $line): int => $line->quantity, $cart->lines)),
            array_filter($cart->lines, static fn (CartLineView $line): bool => $line->problem !== null) !== [],
            $skipped,
            $cart->mayNotOrder?->value,
            $money($cart->shippingFee),
            $money($cart->freeAbove),
            $money($missing),
            $free === null || $free <= 0 ? 0 : min(100, intdiv($goods * 100, $free)),
            $cart->nextGift === null ? null : new CartGiftData($money($cart->nextGift->amount) ?? throw new InvalidArgumentException('No amount.'), $money($cart->nextGift->missing) ?? throw new InvalidArgumentException('No amount.')),
            $cart->pointsToEarn,
            $best['cards'],
            $best['cardFacts'],
        );
    }

    /**
     * @param  list<array{attributeAr: string, attributeEn: string, valueAr: string, valueEn: string}>  $values
     */
    private static function values(array $values, string $locale): string
    {
        $en = $locale === 'en';

        return implode(' · ', array_map(static fn (array $value): string => ($en ? $value['attributeEn'] : $value['attributeAr']).': '.($en ? $value['valueEn'] : $value['valueAr']), $values));
    }

    /** An amount in the store's currency: its sign while the store sells in it, else its letters. */
    private static function money(Money $amount, ?StoreDto $store, int $exponent, string $locale): MoneyData
    {
        try {
            return $store === null ? throw new InvalidArgumentException('No store.') : MoneyData::of($amount, $store, $locale);
        } catch (InvalidArgumentException) {
            return new MoneyData($amount->format($exponent).' '.$amount->currencyCode, $amount->minorUnits);
        }
    }
}
