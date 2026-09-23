<?php

declare(strict_types=1);

namespace Modules\Access\Presentation\Http\Resource;

use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * Somebody who holds a role (frontend.md 3.4, D2).
 *
 * Only the holders the reader manages are listed; the count beside them is everybody, so an admin
 * can see that a role reaches further than the people they can name (access.md amendment 8).
 */
#[TypeScript]
final class RoleHolderRow extends Data
{
    /**
     * @param  list<string>|null  $storeNames  null when they hold it in every store
     */
    public function __construct(
        public string $staffId,
        public string $name,
        /** Null when they hold it in every store. */
        public ?array $storeNames,
    ) {}
}
