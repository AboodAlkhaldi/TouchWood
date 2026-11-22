<?php

declare(strict_types=1);

namespace Modules\Catalog\Presentation\Http\Resource;

use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * One store file (catalog.md §4.4 S10, §1.3; amendment 6(g)): its items as the file gave them, each
 * open one standing as the catalog is now. No uploader is shown (Catalog README, step 6 #19).
 */
#[TypeScript]
final class StoreFilePage extends Data
{
    /**
     * @param  list<StoreFileItemData>  $items
     */
    public function __construct(
        public string $id,
        public string $fileName,
        public string $uploadedAt,
        public string $storeCode,
        public string $storeName,
        public string $storeTimezone,
        /** Whether a module keeps the prices and stock yet (§2.3); until then the page says so, once. */
        public bool $pricesKept,
        public array $items,
    ) {}
}
