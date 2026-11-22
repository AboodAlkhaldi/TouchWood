<?php

declare(strict_types=1);

namespace Modules\Catalog\Application\Command\RemoveStoreFillItems;

use LogicException;
use Modules\Catalog\Application\Audit\ListAudit;
use Modules\Catalog\Application\Import\ImportHeader;
use Modules\Catalog\Application\Import\StoreFillItem;
use Modules\Catalog\Application\Import\StoreFills;
use Modules\Catalog\Domain\Exception\InvalidCatalogAttribute;
use Modules\Catalog\Domain\Exception\ListItemNotFound;
use Shared\Application\Unauthorized;

/**
 * **Removing items from a store file's page** (catalog.md §1.3; amendment 6(g)): `catalog.listing.fill`
 * in the file's store. Open items only; they stay on the page as removed, the record of the file.
 * Audited in the store.
 */
final readonly class RemoveStoreFillItemsHandler
{
    public const string PERMISSION = StoreFills::PERMISSION;

    public function __construct(
        private StoreFills $fills,
    ) {}

    /**
     * @return int how many items it removed
     *
     * @throws InvalidCatalogAttribute|ListItemNotFound|Unauthorized
     */
    public function handle(RemoveStoreFillItems $command): int
    {
        $store = $this->fills->authorize($command->importId);

        return $this->fills->run($command->importId, function (ImportHeader $import, array $items) use ($command, $store): array {
            $removed = [];

            foreach (StoreFills::chosen($command->itemIds, $items) as $item) {
                if ($item->state !== StoreFillItem::OPEN) {
                    throw new InvalidCatalogAttribute("item {$item->number}", 'an item still open');
                }

                $this->fills->save($item->with($item->code, StoreFillItem::REMOVED));
                $removed[] = $item->number;
            }

            return [count($removed), [ListAudit::changed('store_fill', 'removed', $import->id, ['items' => null], ['items' => implode(', ', $removed)], $store) ?? throw new LogicException('No change to record.')]];
        });
    }
}
