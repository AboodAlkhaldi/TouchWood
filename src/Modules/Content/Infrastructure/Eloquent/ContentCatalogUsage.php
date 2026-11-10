<?php

declare(strict_types=1);

namespace Modules\Content\Infrastructure\Eloquent;

use Illuminate\Database\ConnectionInterface;
use Modules\Catalog\Public\Contracts\CatalogUsage;
use Modules\Catalog\Public\Dto\CatalogUseDto;
use Modules\Catalog\Public\Enums\CatalogItem;
use stdClass;

/**
 * What Content holds on to of Catalog's (content.md §1.1 rule 17; catalog.md amendment 19): **the categories its
 * banners and smart bars name**, so Catalog refuses to delete one in use, naming the use, rather than a listing banner
 * silently widening to every list. Each has its RESTRICT key behind this. One query whatever the number of ids.
 * Campaign targets join with campaigns (plan PR 6). Linked products are only ready ones, which Catalog never deletes.
 */
final readonly class ContentCatalogUsage implements CatalogUsage
{
    public function __construct(
        private ConnectionInterface $db,
    ) {}

    public function usesOf(CatalogItem $item, array $ids): array
    {
        if ($item !== CatalogItem::Category || $ids === []) {
            return [];
        }

        $marks = implode(', ', array_fill(0, count($ids), '?'));

        /** @var list<stdClass> $rows */
        $rows = $this->db->select(
            "SELECT 'banner' AS kind, id, category_id FROM content.banners WHERE category_id IN ({$marks})
             UNION ALL SELECT 'smart_bar', id, category_id FROM content.smart_bars WHERE category_id IN ({$marks})
             ORDER BY 1, 2",
            [...$ids, ...$ids],
        );

        return array_map(static fn (stdClass $row): CatalogUseDto => new CatalogUseDto('content', (string) $row->kind, (string) $row->id, $item, (string) $row->category_id), $rows);
    }
}
