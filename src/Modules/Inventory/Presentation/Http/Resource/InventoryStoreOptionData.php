<?php

declare(strict_types=1);

namespace Modules\Inventory\Presentation\Http\Resource;

use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * A store the screen's own store filter offers (frontend.md §2.2): an off one only to whoever may switch
 * stores, marked Off.
 */
#[TypeScript]
final class InventoryStoreOptionData extends Data
{
    public function __construct(
        public string $code,
        public string $name,
        public bool $isActive,
    ) {}
}
