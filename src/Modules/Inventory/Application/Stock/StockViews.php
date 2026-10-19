<?php

declare(strict_types=1);

namespace Modules\Inventory\Application\Stock;

use DateTimeImmutable;
use DateTimeInterface;
use Modules\Catalog\Public\Dto\FoundVariantDto;
use Modules\Catalog\Public\Dto\VariantValueDto;
use Modules\Inventory\Application\Query\StockViews\HoldsView;
use Modules\Inventory\Application\Query\StockViews\SizeView;
use Modules\Inventory\Application\Query\StockViews\StockFiguresView;
use Modules\Inventory\Domain\Service\StockFigures;
use Modules\Inventory\Domain\ValueObject\HoldSummary;
use Modules\Inventory\Domain\ValueObject\StockRow;

/**
 * What Inventory's screens show of a size, written for the page (inventory.md §4.3.1): its figures and
 * its one badge from `StockFigures`, its values in the page's language, moments as ISO 8601. Pure - it
 * reads nothing; the reads hand it what they read. A size with no stock row counts as 0 (§9.2 #6).
 */
final readonly class StockViews
{
    public function __construct(
        private Words $words,
    ) {}

    public function size(FoundVariantDto $found): SizeView
    {
        $locale = $this->words->locale();

        return new SizeView(
            $found->variantId,
            $found->productId,
            $found->productNameAr,
            $found->productNameEn,
            $found->photoMediaId,
            $found->code,
            array_map(static fn (VariantValueDto $value): string => $value->value->in($locale), $found->values),
            $found->archived,
            $found->switchedOn,
        );
    }

    /**
     * @param  DateTimeImmutable|null  $providerChangedAt  a wired store's last PROVIDER line for the size
     */
    public function figures(?StockRow $row, bool $wired, int $defaultThreshold, ?DateTimeImmutable $providerChangedAt, ?HoldSummary $holds): StockFiguresView
    {
        return new StockFiguresView(
            $row->inStock ?? 0,
            $row->held ?? 0,
            StockFigures::free($row, $wired),
            $wired ? ($row->returnedExtra ?? 0) : 0,
            $row->threshold ?? $defaultThreshold,
            $row?->threshold,
            StockFigures::badge($row, $wired, $defaultThreshold)?->value,
            StockFigures::shortBy($row, $wired),
            StockFigures::counts($row, $wired),
            $wired && $row !== null && $row->stockDependent,
            $wired ? $providerChangedAt?->format(DateTimeInterface::ATOM) : null,
            $holds->orders ?? 0,
        );
    }

    public static function holds(?HoldSummary $holds): HoldsView
    {
        $holds ??= HoldSummary::none();

        return new HoldsView($holds->orders, $holds->pieces(), $holds->bought, $holds->gift);
    }
}
