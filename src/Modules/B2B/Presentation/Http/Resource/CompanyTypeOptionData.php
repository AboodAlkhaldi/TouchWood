<?php

declare(strict_types=1);

namespace Modules\B2B\Presentation\Http\Resource;

use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * A company or document type the form offers (b2b.md §1.3), in the home store's order. A greyed one
 * is shown and cannot be chosen.
 */
#[TypeScript]
final class CompanyTypeOptionData extends Data
{
    public function __construct(
        public string $id,
        public string $nameAr,
        public string $nameEn,
        public bool $greyed,
        /** Document types only: required, and still offered. */
        public bool $required,
    ) {}
}
