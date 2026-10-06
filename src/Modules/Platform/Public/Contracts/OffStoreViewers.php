<?php

declare(strict_types=1);

namespace Modules\Platform\Public\Contracts;

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
}
