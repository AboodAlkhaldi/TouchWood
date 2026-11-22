<?php

declare(strict_types=1);

namespace Modules\Catalog\Public\Contracts;

/**
 * Where modules tell Catalog that they hold on to its items (amendment 19). Register once, in your
 * module's service provider; the class is resolved only when an item is deleted.
 */
interface CatalogUsages
{
    /**
     * @param  string  $module  the registering module, e.g. "promotions"
     * @param  string  $usage  the class name of a CatalogUsage, checked when registered
     *
     * @throws \LogicException for a class that is not a CatalogUsage, or one registered twice
     */
    public function register(string $module, string $usage): void;
}
