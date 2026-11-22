<?php

declare(strict_types=1);

namespace Modules\Access\Presentation\Http\Resource;

use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * One line of a home card's short list (frontend.md §2.2).
 */
#[TypeScript]
final class HomeRowBlock extends Data
{
    public function __construct(
        public string $label,
        public ?string $detail,
        /** An ISO-8601 moment, written by the page as its moments are written. */
        public ?string $at,
        public ?string $href,
    ) {}
}
