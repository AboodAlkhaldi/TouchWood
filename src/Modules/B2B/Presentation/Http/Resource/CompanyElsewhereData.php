<?php

declare(strict_types=1);

namespace Modules\B2B\Presentation\Http\Resource;

use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * One of the account's companies in another store (b2b.md amendments 19(c), 20(h)): the company page
 * of this store names it, and offers "Apply in this store" while there is none here.
 */
#[TypeScript]
final class CompanyElsewhereData extends Data
{
    public function __construct(
        public string $storeId,
        public string $storeNameAr,
        public string $storeNameEn,
        public string $name,
        /** PENDING, APPROVED, REJECTED or SUSPENDED — there, not here. */
        public string $status,
    ) {}
}
