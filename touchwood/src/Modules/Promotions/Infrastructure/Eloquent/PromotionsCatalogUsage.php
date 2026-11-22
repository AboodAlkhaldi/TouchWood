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
 * categories and brands its coupons' include and exclude lists name, and the variants its gift levels
 * give — so Catalog refuses to delete one a rule or a level names rather than it being dropped
 * silently. One query whatever the number of ids.
 */
final readonly class PromotionsCatalogUsage implements CatalogUsage
{
    public function __construct(
        private ConnectionInterface $db,
    ) {}

    public function usesOf(CatalogItem $item, array $ids): array
    {
        if ($ids === []) {
            return [];
        }

        [$table, $kind, $owner, $column] = match ($item) {
            CatalogItem::Product => ['coupon_rules', 'coupon', 'coupon_id', 'product_id'],
            CatalogItem::Category => ['coupon_rules', 'coupon', 'coupon_id', 'category_id'],
            CatalogItem::Brand => ['coupon_rules', 'coupon', 'coupon_id', 'brand_id'],
            CatalogItem::Variant => ['gift_levels', 'gift_level', 'id', 'variant_id'],
        };

        return array_values(array_map(
            static fn (stdClass $row): CatalogUseDto => new CatalogUseDto('promotions', $kind, (string) $row->owner_id, $item, (string) $row->item_id),
            $this->db->table("promotions.{$table}")
                ->whereIn($column, $ids)
                ->orderBy($owner)
                ->get(["{$owner} as owner_id", "{$column} as item_id"])
                ->all(),
        ));
    }
}
