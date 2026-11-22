<?php

declare(strict_types=1);

namespace Modules\Platform\Public\Dto;

use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * One point of a home chart (dashboard.md §2 C): a day (`Y-m-d`) or a month (`Y-m`) of the store's own calendar,
 * and each line's value there, by the line's key - null where a line has no such point (the period before has
 * fewer days).
 */
#[TypeScript]
final readonly class HomePoint
{
    /**
     * @param  array<string, int|null>  $values
     */
    public function __construct(
        public string $at,
        public array $values,
    ) {}
}
