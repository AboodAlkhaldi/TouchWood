<?php

declare(strict_types=1);

namespace Modules\Content\Presentation\Http\Resource;

use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * Content › Policies (flags-2026-11.md S3.7): one tab of the policies serving the person's stores (Published or Draft,
 * each tab with its count), last changed first, 50 at a time.
 */
#[TypeScript]
final class PoliciesPageData extends Data
{
    /**
     * @param  array<string, int>  $counts  by state
     * @param  list<PolicyRowData>  $rows
     */
    public function __construct(
        public string $tab,
        public array $counts,
        public array $rows,
        public ?string $next,
    ) {}
}
