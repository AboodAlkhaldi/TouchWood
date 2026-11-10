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
 * banners and smart bars name, and the categories and brands a campaign run names while it may still price them**
 * (`content.campaign_run_targets`, kept for a draft, scheduled or live run), so Catalog refuses to delete one in use,
 * naming the use, rather than a listing banner silently widening to every list or a campaign losing its target. Each
 * has its RESTRICT key behind this. One query whatever the number of ids. Linked products are only ready ones, which
 * Catalog never deletes.
 */
final readonly class ContentCatalogUsage implements CatalogUsage
{
    public function __construct(
        private ConnectionInterface $db,
    ) {}

    public function usesOf(CatalogItem $item, array $ids): array
    {
        if ($ids === [] || ($item !== CatalogItem::Category && $item !== CatalogItem::Brand)) {
            return [];
        }

        $marks = implode(', ', array_fill(0, count($ids), '?'));

        /** @var list<stdClass> $rows */
        $rows = $item === CatalogItem::Category
            ? $this->db->select(
                "SELECT 'banner' AS kind, id, category_id AS target FROM content.banners WHERE category_id IN ({$marks})
                 UNION ALL SELECT 'smart_bar', id, category_id FROM content.smart_bars WHERE category_id IN ({$marks})
                 UNION ALL SELECT 'campaign', run_id, category_id FROM content.campaign_run_targets WHERE category_id IN ({$marks})
                 ORDER BY 1, 2",
                [...$ids, ...$ids, ...$ids],
            )
            : $this->db->select(
                "SELECT 'campaign' AS kind, run_id AS id, brand_id AS target FROM content.campaign_run_targets WHERE brand_id IN ({$marks}) ORDER BY 2",
                $ids,
            );

        return array_map(static fn (stdClass $row): CatalogUseDto => new CatalogUseDto('content', (string) $row->kind, (string) $row->id, $item, (string) $row->target), $rows);
    }
}
