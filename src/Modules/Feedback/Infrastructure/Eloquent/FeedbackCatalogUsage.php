<?php

declare(strict_types=1);

namespace Modules\Feedback\Infrastructure\Eloquent;

use Illuminate\Database\ConnectionInterface;
use Modules\Catalog\Public\Contracts\CatalogUsage;
use Modules\Catalog\Public\Dto\CatalogUseDto;
use Modules\Catalog\Public\Enums\CatalogItem;
use stdClass;

/**
 * What Feedback holds on to of Catalog's (feedback.md §2.3; catalog.md amendment 19): the products
 * its reviews are about and the variants they show — so Catalog refuses to delete one with a review
 * rather than a review being lost. One query whatever the number of ids. (A wishlist item leaves with
 * its product: nothing to keep.)
 */
final readonly class FeedbackCatalogUsage implements CatalogUsage
{
    public function __construct(
        private ConnectionInterface $db,
    ) {}

    public function usesOf(CatalogItem $item, array $ids): array
    {
        $column = match ($item) {
            CatalogItem::Product => 'product_id',
            CatalogItem::Variant => 'variant_id',
            CatalogItem::Category, CatalogItem::Brand => null,
        };

        if ($column === null || $ids === []) {
            return [];
        }

        return array_values(array_map(
            static fn (stdClass $row): CatalogUseDto => new CatalogUseDto('feedback', 'review', (string) $row->id, $item, (string) $row->item_id),
            $this->db->table('feedback.reviews')->whereIn($column, $ids)->orderBy('id')->get(['id', "{$column} as item_id"])->all(),
        ));
    }
}
