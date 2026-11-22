<?php

declare(strict_types=1);

namespace Modules\Sync\Presentation\Http\Resource;

use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * A price or a stock number a read changed (Y2), from and to already written.
 */
#[TypeScript]
final class ReadLineData extends Data
{
    /**
     * @param  list<string>  $values
     */
    public function __construct(
        public string $variantId,
        public ?string $code,
        public bool $sizeExists,
        public ?string $productId,
        public ?string $productNameAr,
        public ?string $productNameEn,
        public array $values,
        public ?string $before,
        public string $after,
    ) {}
}
