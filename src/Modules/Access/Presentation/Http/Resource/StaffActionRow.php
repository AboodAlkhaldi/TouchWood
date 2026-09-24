<?php

declare(strict_types=1);

namespace Modules\Access\Presentation\Http\Resource;

use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * One action a person's role holds, and where it reaches (frontend.md 3.3, C2).
 */
#[TypeScript]
final class StaffActionRow extends Data
{
    /**
     * @param  list<string>|null  $storeNames  the stores this action reaches, already named; null
     *                                         when it reaches every store, or is store-free
     * @param  bool  $exception  true when this action was given stores of its own, apart from the
     *                           rest of the role - which is the only reason the two would differ
     */
    public function __construct(
        public string $name,
        public string $label,
        public string $group,
        public bool $storeFree,
        public ?array $storeNames,
        public bool $exception,
    ) {}
}
