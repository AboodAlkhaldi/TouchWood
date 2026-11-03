<?php

declare(strict_types=1);

namespace Modules\Platform\Public\Contracts;

/**
 * The small list of the panel frame's parts (ops.md §4.15 O1), registered by modules in their service
 * providers and carried with every panel page for a signed-in staff member as `panelFrame`, each under
 * its module's name. The panel's twin of ShopFrameParts.
 */
interface AdminFrameParts
{
    /**
     * @param  class-string  $part  a class implementing AdminFramePart, resolved from the container each
     *                              time it is asked, so it may depend on the request
     *
     * @throws \LogicException for a class that is not an AdminFramePart, or a module registering twice
     */
    public function register(string $module, string $part): void;

    /**
     * Every part, in the order registered, by module.
     *
     * @return array<string, array<string, mixed>>
     */
    public function for(string $locale): array;
}
