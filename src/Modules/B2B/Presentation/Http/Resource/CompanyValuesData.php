<?php

declare(strict_types=1);

namespace Modules\B2B\Presentation\Http\Resource;

use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * What an application holds, or what the company holds now (b2b.md §1.1, §1.2): each value empty
 * while a draft has none. A listed type carries its names; "Other" carries the company's words.
 */
#[TypeScript]
final class CompanyValuesData extends Data
{
    public function __construct(
        public ?string $name,
        public ?string $companyTypeId,
        public ?string $companyTypeNameAr,
        public ?string $companyTypeNameEn,
        public ?string $companyTypeOther,
        public ?string $crNumber,
        public ?string $taxNumber,
        public ?string $address,
        public ?string $note,
    ) {}
}
