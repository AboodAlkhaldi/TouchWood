<?php

declare(strict_types=1);

namespace Modules\Sync\Application\Report;

use Modules\Pricing\Public\Contracts\PricingApi;
use Modules\Pricing\Public\Dto\OfferNameDto;
use Modules\Sync\Domain\ValueObject\ReportKind;

/**
 * **Entries and a read's record, as the screens name them** (sync.md §4.1.3 `ViewSyncReport`, Y1's
 * "What" and "Detail"): each size's product, values and code - two Catalog reads for a whole page -
 * and, only when the page holds one, the sales stopped and the discounts skipped named by Pricing (one
 * read each). A size deleted since keeps the code the provider sent. Its rows are `SyncLog`'s.
 */
final readonly class ReportLines
{
    public function __construct(
        private SizeNames $names,
        private PricingApi $pricing,
        private SyncWords $words,
    ) {}

    /**
     * @param  list<array{kind: string, code: string|null, variantId: string|null, saleId: string|null, detail: array<string, mixed>}>  $rows
     * @return list<ReportedView> in the rows' order
     */
    public function describe(array $rows): array
    {
        $names = $this->names->of(array_column($rows, 'variantId'));
        $sales = $this->named(array_values(array_filter(array_column($rows, 'saleId'))), $this->pricing->salesNamed(...));
        $discounts = $this->named(array_values(array_filter(array_map(static fn (array $row): ?string => $row['kind'] === ReportKind::DiscountSkipped->value && is_string($row['detail']['discount'] ?? null) ? $row['detail']['discount'] : null, $rows))), $this->pricing->discountsNamed(...));

        return array_map(function (array $row) use ($names, $sales, $discounts): ReportedView {
            $size = $row['variantId'] === null ? null : ($names[strtolower($row['variantId'])] ?? null);
            $discount = $row['kind'] === ReportKind::DiscountSkipped->value && is_string($row['detail']['discount'] ?? null) ? $row['detail']['discount'] : null;
            $failed = $row['kind'] === ReportKind::ReadFailed->value;

            return new ReportedView(
                $row['kind'],
                $row['code'] ?? $size['code'] ?? (is_string($row['detail']['code'] ?? null) ? $row['detail']['code'] : null),
                $row['variantId'],
                $size !== null,
                $size['productId'] ?? null,
                $size['nameAr'] ?? null,
                $size['nameEn'] ?? null,
                $size['values'] ?? [],
                $row['saleId'] === null ? null : ($sales[strtolower($row['saleId'])] ?? new OfferRefView($row['saleId'], false, null)),
                $discount === null ? null : ($discounts[strtolower($discount)] ?? new OfferRefView($discount, false, null)),
                $failed && is_string($row['detail']['read'] ?? null) ? $row['detail']['read'] : null,
                $failed ? FailureView::of(is_string($row['detail']['error'] ?? null) ? $row['detail']['error'] : null, $this->words) : null,
                $row['detail'],
            );
        }, $rows);
    }

    /**
     * @param  list<string>  $ids
     * @param  callable(list<string>): list<OfferNameDto>  $name
     * @return array<string, OfferRefView> by id, lower-cased
     */
    private function named(array $ids, callable $name): array
    {
        if ($ids === []) {
            return [];
        }

        $named = [];

        foreach ($name(array_values(array_unique($ids))) as $offer) {
            $named[strtolower($offer->id)] = new OfferRefView($offer->id, $offer->exists, $offer->description);
        }

        return $named;
    }
}
