<?php

declare(strict_types=1);

namespace Modules\Content\Presentation\Http\Resource;

use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * One image a theme part holds, by slot and language (`DESKTOP:ar`, `BAND:any`).
 */
#[TypeScript]
final class ThemePartImageData extends Data
{
    public function __construct(
        public string $key,
        public string $mediaId,
    ) {}
}
