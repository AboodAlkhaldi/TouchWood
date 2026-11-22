<?php

declare(strict_types=1);

namespace Modules\Sync\Infrastructure\Provider;

use Illuminate\Contracts\Container\Container;
use Modules\Sync\Domain\Provider\ProviderAdapter;
use Modules\Sync\Domain\Provider\ProviderAdapters;
use Modules\Sync\Domain\ValueObject\ProviderKind;

/**
 * The adapter for each provider (sync.md §2.1), resolved from the container so a test binds its own -
 * the pretend provider - in place of Odoo's.
 */
final readonly class ContainerProviderAdapters implements ProviderAdapters
{
    public function __construct(
        private Container $container,
    ) {}

    public function for(ProviderKind $provider): ProviderAdapter
    {
        return match ($provider) {
            ProviderKind::Odoo => $this->container->make(OdooAdapter::class),
        };
    }
}
