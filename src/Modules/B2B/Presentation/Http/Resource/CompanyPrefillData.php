<?php

declare(strict_types=1);

namespace Modules\B2B\Presentation\Http\Resource;

use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * What "Apply in this store" starts the form with (b2b.md amendments 19(b), 20(a)): the name of the
 * company in another store, and its type's counterpart in this store's list, its "Other" words, or
 * neither. The address, the CR and tax numbers and the papers are entered fresh. Pressing it posts
 * to `storefront.company.start`, which starts the draft with exactly these.
 */
#[TypeScript]
final class CompanyPrefillData extends Data
{
    public function __construct(
        public string $name,
        public ?string $companyTypeId,
        public ?string $companyTypeOther,
        public string $fromStoreNameAr,
        public string $fromStoreNameEn,
    ) {}
}
