<?php

declare(strict_types=1);

namespace Modules\Inventory\Presentation\Http\Resource;

use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * One line of a size's history (Q22): when, the reason, before and after, the change, the note, and who.
 */
#[TypeScript]
final class HistoryLineData extends Data
{
    public function __construct(
        public string $at,
        public string $reason,
        public int $before,
        public int $after,
        public int $change,
        public ?string $note,
        public string $who,
        public ?string $staffName,
        public bool $staffHidden,
        public ?string $orderId,
    ) {}
}
