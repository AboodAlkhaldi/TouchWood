<?php

declare(strict_types=1);

namespace Modules\Content\Presentation\Http\Resource;

use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * One filled part of a theme (content.md §1.8): its words per language, its link, its promises' words and its images.
 */
#[TypeScript]
final class ThemePartData extends Data
{
    /**
     * @param  list<ThemePromiseData>  $promises
     * @param  list<ThemePartImageData>  $images
     */
    public function __construct(
        public string $part,
        public ?string $textAr,
        public ?string $textEn,
        public ?string $link,
        public array $promises,
        public array $images,
    ) {}
}
