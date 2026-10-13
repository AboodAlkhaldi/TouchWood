<?php

declare(strict_types=1);

namespace Modules\Catalog\Presentation\Http\Resource;

use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/** The files' guide (docs/modules/catalog-import/README.md), as the panel shows it. */
#[TypeScript]
final class ImportGuidePage extends Data
{
    public function __construct(
        public string $html,
    ) {}
}
