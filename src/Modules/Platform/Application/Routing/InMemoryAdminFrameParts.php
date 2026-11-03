<?php

declare(strict_types=1);

namespace Modules\Platform\Application\Routing;

use Illuminate\Contracts\Container\Container;
use LogicException;
use Modules\Platform\Public\Contracts\AdminFramePart;
use Modules\Platform\Public\Contracts\AdminFrameParts;

/**
 * The panel frame's parts, collected from the modules' service providers (ops.md §4.15 O1). As
 * InMemoryShopFrameParts: it keeps class names, not objects, and resolves each when asked, since this
 * list lives for the whole application and a part depends on who is looking.
 */
final class InMemoryAdminFrameParts implements AdminFrameParts
{
    /** @var array<string, class-string<AdminFramePart>> module => class */
    private array $parts = [];

    public function __construct(
        private readonly Container $container,
    ) {}

    public function register(string $module, string $part): void
    {
        if (! is_subclass_of($part, AdminFramePart::class)) {
            throw new LogicException("Module \"{$module}\" registered \"{$part}\", which does not implement ".AdminFramePart::class.'.');
        }

        if (isset($this->parts[$module])) {
            throw new LogicException("Module \"{$module}\" already has a part in the panel's frame, \"{$this->parts[$module]}\".");
        }

        $this->parts[$module] = $part;
    }

    public function for(string $locale): array
    {
        $frame = [];

        foreach ($this->parts as $module => $class) {
            $part = $this->container->make($class);

            // Checked when it was registered; resolved from the container, so asked again.
            if (! $part instanceof AdminFramePart) {
                throw new LogicException("\"{$class}\" does not implement ".AdminFramePart::class.'.');
            }

            $frame[$module] = $part->partFor($locale);
        }

        return $frame;
    }
}
