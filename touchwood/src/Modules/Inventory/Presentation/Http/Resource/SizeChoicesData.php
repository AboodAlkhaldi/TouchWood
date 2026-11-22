<?php

declare(strict_types=1);

namespace Modules\Inventory\Presentation\Http\Resource;

use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * Add Size's picker (Q24): the ready sizes not listed here, and up to 5 listed ones a search found
 * ("Already listed").
 */
#[TypeScript]
final class SizeChoicesData extends Data
{
    /**
     * @param  list<StockSizeData>  $rows
     * @param  list<StockSizeData>  $alreadyListed
     */
    public function __construct(
        public array $rows,
        public ?string $next,
        public int $total,
        public array $alreadyListed,
    ) {}
}
