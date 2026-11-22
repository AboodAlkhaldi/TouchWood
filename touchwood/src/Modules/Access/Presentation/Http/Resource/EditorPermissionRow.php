<?php

declare(strict_types=1);

namespace Modules\Access\Presentation\Http\Resource;

use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * One action the editor offers (frontend.md 3.4, D3).
 */
#[TypeScript]
final class EditorPermissionRow extends Data
{
    public function __construct(
        public string $name,
        public string $label,
        public string $group,
        /** Store-free: it reaches every store by its nature, so it takes no store choice. */
        public bool $storeFree,
        /** False when the author does not hold it: shown, never tickable. */
        public bool $grantable,
    ) {}
}
