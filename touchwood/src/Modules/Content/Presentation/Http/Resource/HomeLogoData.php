<?php

declare(strict_types=1);

namespace Modules\Content\Presentation\Http\Resource;

use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/** One client logo of the logos band (content.md §1.4 #8): the file as its box shows it, its name per language, its link. */
#[TypeScript]
final class HomeLogoData extends Data
{
    public function __construct(
        public string $mediaId,
        public ?ContentImageData $image,
        public string $nameAr,
        public ?string $nameEn,
        public ?string $link,
    ) {}
}
