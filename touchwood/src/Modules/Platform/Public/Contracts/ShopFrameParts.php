<?php

declare(strict_types=1);

namespace Modules\Platform\Public\Contracts;

use Modules\Platform\Public\Dto\StoreDto;

/**
 * The small list of the shop frame's parts (catalog.md §4.5; owner, 2026-10-07, #9), registered by
 * modules in their service providers and carried with every shop page under a store as `frame`, each
 * under its module's name.
 */
interface ShopFrameParts
{
    /**
     * @param  class-string  $part  a class implementing ShopFramePart, resolved from the container each
     *                              time it is asked, so it may depend on the request
     *
     * @throws \LogicException for a class that is not a ShopFramePart, or a module registering twice
     */
    public function register(string $module, string $part): void;

    /**
     * Every part, in the order registered, by module.
     *
     * @return array<string, array<string, mixed>>
     */
    public function for(StoreDto $store, string $locale): array;
}
