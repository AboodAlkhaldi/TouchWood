<?php

declare(strict_types=1);

namespace Modules\Content\Presentation\Http\Resource;

use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * W5 · Themes (content.md §4.4), a Super Admin's: every theme, newest first, 50 at a time.
 */
#[TypeScript]
final class ThemesPageData extends Data
{
    /**
     * @param  list<ThemeRowData>  $themes
     */
    public function __construct(
        public array $themes,
        public ?string $next,
    ) {}
}
