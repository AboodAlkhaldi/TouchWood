<?php

declare(strict_types=1);

namespace Modules\Catalog\Application\Command\CorrectStoreFillCode;

use LogicException;
use Modules\Catalog\Application\Audit\ListAudit;
use Modules\Catalog\Application\Import\ImportHeader;
use Modules\Catalog\Application\Import\StoreFillItem;
use Modules\Catalog\Application\Import\StoreFills;
use Modules\Catalog\Domain\Exception\InvalidCatalogAttribute;
use Modules\Catalog\Domain\Exception\ListItemNotFound;
use Modules\Catalog\Domain\ValueObject\ProductCode;
use Shared\Application\Unauthorized;

/**
 * **Mending an item's code on a store file's page** (catalog.md §1.3; amendment 6(g)):
 * `catalog.listing.fill` in the file's store. An open item takes a code of 1 to 10 digits that no other
 * item of the file has (each code once); the page then reads it against the catalog again. Audited in
 * the store.
 */
final readonly class CorrectStoreFillCodeHandler
{
    public const string PERMISSION = StoreFills::PERMISSION;

    public function __construct(
        private StoreFills $fills,
    ) {}

    /**
     * @throws InvalidCatalogAttribute|ListItemNotFound|Unauthorized
     */
    public function handle(CorrectStoreFillCode $command): void
    {
        $store = $this->fills->authorize($command->importId);
        $code = ProductCode::of($command->code)->value;

        $this->fills->run($command->importId, function (ImportHeader $import, array $items) use ($command, $code, $store): array {
            $item = $items[strtolower($command->itemId)] ?? throw new ListItemNotFound($command->itemId);

            if ($item->state !== StoreFillItem::OPEN) {
                throw new InvalidCatalogAttribute('item', 'an item still open');
            }

            foreach ($items as $other) {
                if ($other->id !== $item->id && $other->code === $code && $other->state !== StoreFillItem::REMOVED) {
                    throw new InvalidCatalogAttribute('code', 'a code no other item of the file has');
                }
            }

            if ($code === $item->code) {
                return [null, []];
            }

            $this->fills->save($item->with($code, $item->state));

            return [null, [ListAudit::changed('store_fill_item', 'corrected', $item->id, ['code' => $item->code], ['code' => $code], $store) ?? throw new LogicException('No change to record.')]];
        });
    }
}
