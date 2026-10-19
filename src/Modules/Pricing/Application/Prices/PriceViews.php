<?php

declare(strict_types=1);

namespace Modules\Pricing\Application\Prices;

use DateTimeImmutable;
use DateTimeInterface;
use Modules\Catalog\Public\Dto\FoundVariantDto;
use Modules\Catalog\Public\Dto\VariantValueDto;
use Modules\Catalog\Public\Enums\SaleMode;
use Modules\Platform\Public\Dto\MoneyData;
use Modules\Platform\Public\Dto\StoreDto;
use Modules\Pricing\Application\Query\PriceViews\BandView;
use Modules\Pricing\Application\Query\PriceViews\RetailPriceView;
use Modules\Pricing\Application\Query\PriceViews\SizeView;
use Modules\Pricing\Application\Query\PriceViews\TodayPrice;
use Modules\Pricing\Domain\Service\PriceResolution;
use Modules\Pricing\Domain\ValueObject\Band;
use Modules\Pricing\Domain\ValueObject\Candidate;
use Modules\Pricing\Domain\ValueObject\RetailPrice;
use Shared\Domain\ValueObject\Money;

/**
 * What Pricing's screens show of a size's prices, written for the page (pricing.md §4.3.1 "Money and
 * numbers", S4): every amount already written in the store's currency (`MoneyData`), moments as ISO 8601,
 * a size's values in the page's language. Pure - it reads nothing; the reads hand it what they read.
 *
 * An amount kept in another currency than the store's - the store's currency changed since - is not
 * written with the store's decimals and sign: it shows as none until it is set again (as Catalog's list).
 */
final readonly class PriceViews
{
    public function __construct(
        private Words $words,
    ) {}

    public function money(Money $money, StoreDto $store): ?MoneyData
    {
        return $money->currencyCode === $store->currencyCode ? MoneyData::of($money, $store, $this->words->locale()) : null;
    }

    public function size(FoundVariantDto $found): SizeView
    {
        return new SizeView(
            $found->variantId,
            $found->productId,
            $found->productNameAr,
            $found->productNameEn,
            $found->photoMediaId,
            $found->code,
            $this->values($found->values),
            $found->archived,
            $found->switchedOn,
        );
    }

    /**
     * @param  list<VariantValueDto>  $values
     * @return list<string> in the page's language
     */
    public function values(array $values): array
    {
        $locale = $this->words->locale();

        return array_map(static fn (VariantValueDto $value): string => $value->value->in($locale), $values);
    }

    /**
     * A size's name for a sentence: its values joined ("60 cm · Oak"), or "One Size".
     *
     * @param  list<VariantValueDto>  $values
     */
    public function sizeName(array $values): string
    {
        return $values === [] ? $this->words->line('names.one_size') : implode(' · ', $this->values($values));
    }

    public function retail(?RetailPrice $retail, StoreDto $store): ?RetailPriceView
    {
        $price = $retail === null ? null : $this->money($retail->amount, $store);

        return $retail === null || $price === null ? null : new RetailPriceView($price, $retail->source->value, $retail->updatedAt->format(DateTimeInterface::ATOM));
    }

    /**
     * What a line of this mode and quantity pays at that moment, from the size's candidates (§1.6) - none
     * without a retail price.
     *
     * @param  list<Candidate>  $candidates
     */
    public function today(array $candidates, SaleMode $mode, int $quantity, DateTimeImmutable $at, StoreDto $store): ?TodayPrice
    {
        $resolved = PriceResolution::resolve($candidates, $mode, $quantity, $at);
        $price = $resolved === null ? null : $this->money($resolved->unit, $store);

        if ($resolved === null || $price === null) {
            return null;
        }

        return new TodayPrice(
            $price,
            $resolved->unit->compareTo($resolved->listUnit) < 0 ? $this->money($resolved->listUnit, $store) : null,
            $resolved->kind->value,
            $resolved->alwaysWins,
            $resolved->sourceId,
            self::moment($resolved->endsAt),
        );
    }

    /**
     * @param  list<Band>  $bands
     * @return list<BandView>
     */
    public function bands(array $bands, StoreDto $store): array
    {
        $views = [];

        foreach ($bands as $band) {
            $price = $this->money($band->amount, $store);

            if ($price !== null) {
                $views[] = new BandView($band->fromQuantity, $price);
            }
        }

        return $views;
    }

    public static function moment(?DateTimeImmutable $moment): ?string
    {
        return $moment?->format(DateTimeInterface::ATOM);
    }
}
