<?php

declare(strict_types=1);

namespace Modules\Platform\Public\Contracts;

/**
 * Where modules tell Platform that they store media ids. Register once, in your module's service
 * provider; the class is resolved only when media is deleted.
 */
interface MediaUsages
{
    /**
     * @param  string  $module  the registering module, e.g. "catalog"
     * @param  string  $usage  the class name of a MediaUsage, checked when registered
     *
     * @throws \LogicException for a class that is not a MediaUsage, or one registered twice
     */
    public function register(string $module, string $usage): void;
}
