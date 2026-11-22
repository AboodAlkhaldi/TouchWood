<?php

declare(strict_types=1);

namespace Modules\Catalog\Application\Query\Lists;

use Shared\Application\Authorizer;
use Shared\Application\Unauthorized;

/**
 * **Who may read a shared list, and who may change it** (catalog.md §4.4, P2): anyone holding the
 * list's job in any store reads it — reading is part of every job on it, as B2B's type lists are —
 * while every change still needs the job **with All stores** (§1.5–§1.11), which `storesWith()`
 * answers as null ("every store, now and for any store added later"). Someone holding the job
 * nowhere is refused by its name before anything is read.
 */
final readonly class ListReaders
{
    public function __construct(
        private Authorizer $authorizer,
    ) {}

    /**
     * Named as every Catalog check is (`SharedListChange::authorize`, `ProductAccess::authorize`):
     * the reader must hold one of these jobs in some store. Answers whether they may change the
     * list - the first job, held with All stores - from the same read, so a page asks each job once
     * (frontend.md §5).
     *
     * @throws Unauthorized when the reader holds none of these jobs in any store
     */
    public function authorize(string ...$permissions): bool
    {
        foreach ($permissions as $index => $permission) {
            $stores = $this->authorizer->storesWith($permission);

            if ($stores !== []) {
                return $index === 0 && $stores === null;
            }
        }

        throw new Unauthorized($permissions[0] ?? 'catalog');
    }
}
