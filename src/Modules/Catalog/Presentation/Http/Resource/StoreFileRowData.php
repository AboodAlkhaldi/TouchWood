<?php

declare(strict_types=1);

namespace Modules\Catalog\Presentation\Http\Resource;

use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/** One store file in its store's list (§4.4 S10): name · when · items · items still open. */
#[TypeScript]
final class StoreFileRowData extends Data
{
    public function __construct(
        public string $id,
        public string $fileName,
        public string $uploadedAt,
        public int $items,
        public int $open,
    ) {}
}
