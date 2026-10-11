<?php

declare(strict_types=1);

namespace Modules\Catalog\Presentation\Http\Resource;

use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * One item of a store file: # · code · its product · price · stock; `OPEN` with where it stands now
 * (`READY`, `NOT_READY` and what it lacks, `ARCHIVED`, `ALREADY_ON`, `UNKNOWN`), or `ON` / `REMOVED`.
 */
#[TypeScript]
final class StoreFileItemData extends Data
{
    /**
     * @param  list<string>  $missing  for one not ready, as the product page names them
     * @param  list<string>  $lines  what the modules keeping prices and stock say of it (§2.3)
     */
    public function __construct(
        public string $id,
        public int $number,
        public string $code,
        public string $price,
        public ?int $stock,
        public string $state,
        public ?string $standing,
        public ?string $productId,
        public ?string $productNameAr,
        public ?string $productNameEn,
        public array $missing,
        public array $lines,
    ) {}
}
