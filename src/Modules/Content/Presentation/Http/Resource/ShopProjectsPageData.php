<?php

declare(strict_types=1);

namespace Modules\Content\Presentation\Http\Resource;

use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/** H5, the projects (content.md §1.13, §4.5): photo, title and city of each published project, newest first, 12 then Show More. */
#[TypeScript]
final class ShopProjectsPageData extends Data
{
    /**
     * @param  list<array<string, mixed>>  $projects
     */
    public function __construct(
        public array $projects,
        public ?string $next,
        public ContentAddressData $address,
        public ContentHeadData $head,
    ) {}
}
