<?php

declare(strict_types=1);

namespace Modules\Catalog\Public\Contracts;

/**
 * **The import's sections** (catalog.md §2.3): a registry, like Platform's `MediaUsages`, where a module
 * above Catalog registers the class that reads its part of a products file or a store file, shows its
 * lines on the file's page, and writes its part when a product is accepted in a store — Pricing the
 * prices, Inventory the stock (stage 5).
 *
 * **Declared in step 6, as `ListingFacts` was in step 5 (amendment 5(i)): Catalog implements and binds
 * it with stage 5**, which registers the first section. Until then the registry is empty, and each
 * file's page says, once, that prices and stock were shown and not kept.
 */
interface ImportSections
{
    /**
     * @param  class-string<ImportSection>  $section
     */
    public function register(string $module, string $section): void;
}
