<?php

declare(strict_types=1);

namespace Modules\Platform\Public\Contracts;

use Shared\Domain\ValueObject\StoreId;

/**
 * Where a module tells Platform who may see an off store in the shop (platform.md §2.7). Register
 * once, in your module's service provider; one viewer per module. With none saying yes, an off
 * store is a 404, as if it were never there (§1.6).
 */
interface OffStoreViewers
{
    /**
     * @param  string  $module  the registering module, e.g. "access"
     * @param  string  $viewer  the class name of an OffStoreViewer, checked when registered
     *
     * @throws \LogicException for a class that is not an OffStoreViewer, or a module's second
     */
    public function register(string $module, string $viewer): void;

    /**
     * Whether any registered viewer lets the request being answered see an off store - today a staff view of an active
     * Super Admin (Access's). Content's Preview Theme asks it of the shop too (content.md W5b, PR 9): the preview's
     * reserved job is a Super Admin's alone, and the shop knows no staff member but through such a view.
     */
    public function mayView(StoreId $store): bool;
}
