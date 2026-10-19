<?php

declare(strict_types=1);

namespace Modules\Catalog\Public\Contracts;

/**
 * The parts other modules add to a product's page in the shop (catalog.md amendment 26(b)), registered
 * in their service providers. The page carries them as `sections`, keyed `{module}.{key}`; Catalog
 * never knows what is in them.
 */
interface ShopProductSections
{
    /**
     * @param  string  $key  `{module}.{key}` — "feedback.reviews"
     * @param  class-string  $section  a class implementing ShopProductSection, resolved from the
     *                                 container on each request
     *
     * @throws \LogicException for a class that is not a ShopProductSection, a key not of that form, or
     *                         a key registered twice
     */
    public function register(string $key, string $section): void;
}
