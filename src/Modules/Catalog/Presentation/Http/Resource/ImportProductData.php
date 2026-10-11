<?php

declare(strict_types=1);

namespace Modules\Catalog\Presentation\Http\Resource;

use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * One product of a file (§1.12): as it will come in, the catalog product its codes find (named), the
 * decision, its state and - brought in - what it still lacks.
 */
#[TypeScript]
final class ImportProductData extends Data
{
    /**
     * @param  list<string>  $codes
     * @param  array<string, string>|null  $newCodes  each code the catalog has → its new one
     * @param  list<string>  $missing
     * @param  list<string>  $addressTaken  the languages whose web address another product holds
     */
    public function __construct(
        public string $id,
        public int $number,
        public string $nameAr,
        public ?string $nameEn,
        public array $codes,
        public ?string $conflictProductId,
        public ?string $conflictNameAr,
        public ?string $conflictNameEn,
        public ?string $decision,
        public ?array $newCodes,
        public string $state,
        public ?string $productId,
        public bool $changed,
        public array $missing,
        public array $addressTaken,
        public bool $onSale,
        public ?string $sale,
        public ?string $refusal,
        public bool $codeChange,
        public bool $attributeChange,
    ) {}
}
