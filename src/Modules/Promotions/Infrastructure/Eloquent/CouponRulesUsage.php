<?php

declare(strict_types=1);

namespace Modules\Promotions\Infrastructure\Eloquent;

use Illuminate\Database\ConnectionInterface;
use Modules\Catalog\Public\Contracts\CatalogUsage;
use Modules\Catalog\Public\Dto\CatalogUseDto;
use Modules\Catalog\Public\Enums\CatalogItem;
use stdClass;

/**
 * What Promotions holds on to of Catalog's (promotions.md §1.2; catalog.md amendment 19): the products,
 * categories and brands its coupons' include and exclude lists name — so Catalog refuses to delete one
 * a rule names rather than a rule being dropped silently. Gift levels' variants join with step 5.
 * One query whatever the number of ids.
 */
final readonly class CouponRulesUsage implements CatalogUsage
{
    public function __construct(
        private ConnectionInterface $db,
    ) {}

    public function usesOf(CatalogItem $item, array $ids): array
    {
        $column = match ($item) {
            CatalogItem::Product => 'product_id',
            CatalogItem::Category => 'category_id',
            CatalogItem::Brand => 'brand_id',
            CatalogItem::Variant => null,
        };

        if ($column === null || $ids === []) {
            return [];
        }

        return array_values(array_map(
            static fn (stdClass $row): CatalogUseDto => new CatalogUseDto('promotions', 'coupon', (string) $row->coupon_id, $item, (string) $row->item_id),
            $this->db->table('promotions.coupon_rules')
                ->whereIn($column, $ids)
                ->orderBy('coupon_id')
                ->get(['coupon_id', "{$column} as item_id"])
                ->all(),
        ));
    }
}
