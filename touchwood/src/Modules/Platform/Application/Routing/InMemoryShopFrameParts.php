<?php

declare(strict_types=1);

namespace Modules\Platform\Application\Routing;

use Illuminate\Contracts\Container\Container;
use LogicException;
use Modules\Platform\Public\Contracts\ShopFramePart;
use Modules\Platform\Public\Contracts\ShopFrameParts;
use Modules\Platform\Public\Dto\StoreDto;

/**
 * The shop frame's parts, collected from the modules' service providers (catalog.md §4.5). It keeps
 * class names, not objects, and resolves each when asked: this list lives for the whole application,
 * and a part may depend on the request it answers — who is looking (the project's rule: nothing that
 * depends on the person acting is held by a singleton).
 */
final class InMemoryShopFrameParts implements ShopFrameParts
{
    /** @var array<string, class-string<ShopFramePart>> module => class */
    private array $parts = [];

    public function __construct(
        private readonly Container $container,
    ) {}

    public function register(string $module, string $part): void
    {
        if (! is_subclass_of($part, ShopFramePart::class)) {
            throw new LogicException("Module \"{$module}\" registered \"{$part}\", which does not implement ".ShopFramePart::class.'.');
        }

        if (isset($this->parts[$module])) {
            throw new LogicException("Module \"{$module}\" already has a part in the shop's frame, \"{$this->parts[$module]}\".");
        }

        $this->parts[$module] = $part;
    }

    public function for(StoreDto $store, string $locale): array
    {
        $frame = [];

        foreach ($this->parts as $module => $class) {
            $part = $this->container->make($class);

            // Checked when it was registered; resolved from the container, so asked again.
            if (! $part instanceof ShopFramePart) {
                throw new LogicException("\"{$class}\" does not implement ".ShopFramePart::class.'.');
            }

            $frame[$module] = $part->partFor($store, $locale);
        }

        return $frame;
    }
}
