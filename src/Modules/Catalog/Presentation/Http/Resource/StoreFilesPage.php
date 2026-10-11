<?php

declare(strict_types=1);

namespace Modules\Catalog\Presentation\Http\Resource;

use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * A store's files (catalog.md §4.4 S10): newest first, a page at a time, for the store chosen in the
 * page's filter among those where the reader fills stores; a refused upload's problems, once.
 */
#[TypeScript]
final class StoreFilesPage extends Data
{
    /**
     * @param  list<StoreFileRowData>  $files
     * @param  list<StoreOptionData>  $stores  the store filter: where the reader fills stores
     * @param  list<ImportProblemData>|null  $problems  the last upload's, when it was refused
     */
    public function __construct(
        public array $files,
        public int $page,
        public bool $more,
        public int $total,
        public string $storeCode,
        public string $storeName,
        /** The store's zone for the moments shown (frontend.md §1.10). */
        public string $storeTimezone,
        public array $stores,
        public ?array $problems,
        /** How many problems the refused file had: the list holds the first 500. */
        public ?int $problemsTotal,
    ) {}
}
