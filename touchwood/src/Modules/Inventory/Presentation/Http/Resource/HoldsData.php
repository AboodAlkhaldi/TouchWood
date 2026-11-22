<?php

declare(strict_types=1);

namespace Modules\Inventory\Presentation\Http\Resource;

use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * What orders hold of a size (Q21): how many orders, how many pieces, bought and gift apart.
 */
#[TypeScript]
final class HoldsData extends Data
{
    public function __construct(
        public int $orders,
        public int $pieces,
        public int $bought,
        public int $gift,
    ) {}
}
