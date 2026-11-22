<?php

declare(strict_types=1);

namespace Modules\B2B\Presentation\Http\Resource;

use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * Something the last rejection asked this company for (amendment 4): a text or a file.
 */
#[TypeScript]
final class CompanyRequestData extends Data
{
    public function __construct(
        public string $id,
        /** TEXT or FILE. */
        public string $kind,
        /** The staff member's own words. */
        public string $label,
    ) {}
}
