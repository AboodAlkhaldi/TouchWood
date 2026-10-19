<?php

declare(strict_types=1);

namespace Modules\Inventory\Presentation\Http\Resource;

use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * Why a row's control is drawn out of reach (inventory.md §4.3.1), in the page's language -
 * `InventoryActionsForReader::reasons()`.
 */
#[TypeScript]
final class InventoryReasonsData extends Data
{
    public function __construct(
        public string $wired,
        public string $archived,
    ) {}
}
