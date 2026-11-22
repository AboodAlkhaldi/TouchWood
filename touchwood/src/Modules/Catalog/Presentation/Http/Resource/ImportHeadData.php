<?php

declare(strict_types=1);

namespace Modules\Catalog\Presentation\Http\Resource;

use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/** An import above its four parts (§4.4 S11). */
#[TypeScript]
final class ImportHeadData extends Data
{
    public function __construct(
        public string $id,
        public string $fileName,
        public string $state,
        public ?string $failure,
        public bool $withPhotos,
        public ?string $uploadedBy,
        public string $uploadedAt,
        public int $products,
    ) {}
}
