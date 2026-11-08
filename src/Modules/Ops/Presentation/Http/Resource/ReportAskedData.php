<?php

declare(strict_types=1);

namespace Modules\Ops\Presentation\Http\Resource;

use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * A report's choices as the Reports page holds them (ops.md §4.15 O8): the store's code or `all`, as the page's address
 * names it (owner, 2026-11-05), the first and last days (`YYYY-MM-DD`, empty when none) and `day` or `month`. The
 * address carries them once Show Report is pressed; the server reads the store by its id (§4.6).
 */
#[TypeScript]
final class ReportAskedData extends Data
{
    public function __construct(
        public string $store,
        public string $from,
        public string $to,
        public string $by,
    ) {}
}
