<?php

declare(strict_types=1);

namespace Modules\Catalog\Presentation\Http\Resource;

use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * A product's or a variant's photo (catalog.md §4.4 S9): its thumbnail once its sizes are ready,
 * and their state.
 */
#[TypeScript]
final class PhotoData extends Data
{
    /**
     * @param  string  $state  PENDING, READY or FAILED
     */
    public function __construct(
        public string $mediaId,
        public ?string $thumb,
        public string $state,
    ) {}
}
