<?php

declare(strict_types=1);

namespace Modules\Access\Presentation\Http\Resource;

use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * One saved role in the list (frontend.md 3.4, D1).
 */
#[TypeScript]
final class RoleRow extends Data
{
    /**
     * @param  list<string>  $groups  the business areas it reaches into
     */
    public function __construct(
        public string $id,
        /** Its name in the language the panel is being read in. */
        public string $name,
        /** "ADMIN" or "STAFF": an admin role holds the management actions. */
        public string $level,
        public int $permissionCount,
        public int $holderCount,
        /**
         * Whether this reader may open it for editing. False for an admin role unless they are a
         * Super Admin, and false when somebody holding it works outside their stores - they would
         * be changing what a person they do not manage may do.
         */
        public bool $editable,
        /** Which business areas it reaches into, for the table of roles against areas. */
        public array $groups,
    ) {}
}
