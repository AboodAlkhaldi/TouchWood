<?php

declare(strict_types=1);

namespace Modules\B2B\Presentation\Http\Resource;

use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * An item the last rejection marked (amendment 4): a field, or a document type.
 */
#[TypeScript]
final class CompanyFlagData extends Data
{
    public function __construct(
        /** name, company_type, cr_number, tax_number or address; null for a document. */
        public ?string $field,
        public ?string $documentTypeId,
    ) {}
}
