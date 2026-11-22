<?php

declare(strict_types=1);

namespace Modules\Catalog\Presentation\Http\Resource;

use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * One of a product's YouTube videos on its Photos tab (flags-2026-11.md S4.4, catalog.md amendment 48): the video's
 * id, and its title in each language when it has one.
 */
#[TypeScript]
final class VideoData extends Data
{
    public function __construct(
        public string $videoId,
        public ?string $titleAr,
        public ?string $titleEn,
    ) {}
}
