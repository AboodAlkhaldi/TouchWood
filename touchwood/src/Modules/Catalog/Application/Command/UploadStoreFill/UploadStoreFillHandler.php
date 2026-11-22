<?php

declare(strict_types=1);

namespace Modules\Catalog\Application\Command\UploadStoreFill;

use Modules\Catalog\Application\Audit\ListAudit;
use Modules\Catalog\Application\CatalogPermissions;
use Modules\Catalog\Application\Import\Imports;
use Modules\Catalog\Application\Import\StoreFillFile;
use Modules\Catalog\Application\Listing\StoreListingChange;
use Modules\Catalog\Domain\Exception\ImportRefused;
use Modules\Catalog\Domain\Exception\InvalidCatalogAttribute;
use Modules\Catalog\Public\Contracts\StoreWiring;
use Shared\Application\ActorContext;
use Shared\Application\ActorType;
use Shared\Application\Unauthorized;
use Shared\Domain\ValueObject\StoreId;

/**
 * **Uploading an admins' store file** (catalog.md §1.3; amendment 6(g), (h)): `catalog.listing.fill`
 * in that store — admin roles only. The file is read and checked whole (`StoreFillFile`: each code
 * once, 1,000 items, 2 MB) and refused with every problem listed, or kept as the store's file with its
 * items open. **Read by the store's wiring** (amendment 43): a store wired to Odoo gives codes only, one
 * not wired its prices and stock - asked inside the change, so a store wired or unwired meanwhile is
 * read as it is when the file is kept. **It never creates or changes a product**: whether each code is
 * the catalog's, and its product ready, is read on the page. Audited in the store.
 */
final readonly class UploadStoreFillHandler
{
    public const string PERMISSION = CatalogPermissions::LISTING_FILL;

    public function __construct(
        private StoreListingChange $change,
        private ActorContext $actors,
        private Imports $imports,
        private StoreWiring $wiring,
    ) {}

    /**
     * @return string the file's id
     *
     * @throws ImportRefused|InvalidCatalogAttribute|Unauthorized
     */
    public function handle(UploadStoreFill $command): string
    {
        $store = $this->change->authorize(self::PERMISSION, $command->storeId)->value;
        $size = @filesize($command->path);
        $json = $size === false || $size > StoreFillFile::MAX_BYTES ? false : @file_get_contents($command->path);

        if (! is_string($json)) {
            throw new ImportRefused([['at' => 'file', 'problem' => 'a JSON file of at most 2 MB']]);
        }

        $id = $this->imports->nextId();
        $actor = $this->actors->current();
        $fileName = mb_substr(trim(basename(str_replace('\\', '/', $command->fileName))), 0, 255);

        return $this->change->run(function () use ($id, $store, $fileName, $actor, $json): array {
            $file = StoreFillFile::read($json, $this->wiring->isWired(StoreId::fromString($store)));
            $this->imports->addStoreFill($id, $store, $fileName, $actor->type === ActorType::Staff ? $actor->id : null, $file->items);

            return [$id, [ListAudit::added('store_fill', $id, ['file_name' => $fileName, 'items' => count($file->items)], $store)]];
        });
    }
}
