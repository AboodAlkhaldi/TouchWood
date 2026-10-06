<?php

declare(strict_types=1);

namespace Modules\Platform\Application\Routing;

use Illuminate\Contracts\Container\Container;
use LogicException;
use Modules\Platform\Public\Contracts\OffStoreViewer;
use Modules\Platform\Public\Contracts\OffStoreViewers;
use Shared\Domain\ValueObject\StoreId;

/**
 * The modules' off-store viewers, collected from their service providers (platform.md §2.7). Each
 * is resolved when asked, so a viewer that reads the request - Access's staff view - reads this one.
 */
final class InMemoryOffStoreViewers implements OffStoreViewers
{
    /** @var array<string, class-string<OffStoreViewer>> module => class */
    private array $viewers = [];

    public function __construct(
        private readonly Container $container,
    ) {}

    public function register(string $module, string $viewer): void
    {
        if (! is_subclass_of($viewer, OffStoreViewer::class)) {
            throw new LogicException("Module \"{$module}\" registered \"{$viewer}\", which does not implement ".OffStoreViewer::class.'.');
        }

        if (isset($this->viewers[$module])) {
            throw new LogicException("Module \"{$module}\" already has an off-store viewer, \"{$this->viewers[$module]}\".");
        }

        $this->viewers[$module] = $viewer;
    }

    /** Whether any module's viewer lets this request see the off store. None registered: no. */
    public function mayView(StoreId $store): bool
    {
        foreach ($this->viewers as $viewer) {
            $resolved = $this->container->make($viewer);

            if ($resolved instanceof OffStoreViewer && $resolved->mayView($store)) {
                return true;
            }
        }

        return false;
    }
}
