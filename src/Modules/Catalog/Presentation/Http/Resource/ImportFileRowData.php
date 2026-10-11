<?php

declare(strict_types=1);

namespace Modules\Catalog\Presentation\Http\Resource;

use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * One products file in the list: name · who uploaded it (Platform's `StaffNames`, a Super Admin named
 * to another Super Admin) · when · products · state.
 */
#[TypeScript]
final class ImportFileRowData extends Data
{
    public function __construct(
        public string $id,
        public string $fileName,
        public ?string $uploadedBy,
        public string $uploadedAt,
        public int $products,
        public string $state,
    ) {}
}
