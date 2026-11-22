<?php

declare(strict_types=1);

namespace Modules\B2B\Presentation\Http\Resource;

use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * A store a staff screen may be filtered by, named in the panel's language (b2b.md §4.6, amendment
 * 30): the company list's filter carries its id, the type lists' its code.
 */
#[TypeScript]
final class StaffStoreOptionData extends Data
{
    public function __construct(
        public string $id,
        public string $code,
        public string $name,
        /** False for an off store, offered only to a Super Admin and marked Off (platform.md §9.10). */
        public bool $isActive,
    ) {}
}
