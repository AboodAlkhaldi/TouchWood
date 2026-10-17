<?php

declare(strict_types=1);

namespace Modules\Catalog\Application\Usage;

use Illuminate\Contracts\Container\Container;
use LogicException;
use Modules\Catalog\Domain\Exception\CatalogItemInUse;
use Modules\Catalog\Public\Contracts\CatalogUsage;
use Modules\Catalog\Public\Contracts\CatalogUsages;
use Modules\Catalog\Public\Dto\CatalogUseDto;
use Modules\Catalog\Public\Enums\CatalogItem;

/**
 * The modules that hold on to Catalog's items (amendment 19), collected from their service providers
 * at boot, as Platform's media usages are. Catalog's deletes ask it before removing anything.
 */
final class InMemoryCatalogUsages implements CatalogUsages
{
    /** @var array<class-string<CatalogUsage>, string> class => registering module */
    private array $usages = [];

    public function __construct(
        private readonly Container $container,
    ) {}

    public function register(string $module, string $usage): void
    {
        if (! is_subclass_of($usage, CatalogUsage::class)) {
            throw new LogicException("Module \"{$module}\" registered \"{$usage}\", which does not implement ".CatalogUsage::class.'.');
        }

        if (isset($this->usages[$usage])) {
            throw new LogicException("\"{$usage}\" is already registered by module \"{$this->usages[$usage]}\".");
        }

        $this->usages[$usage] = $module;
    }

    /**
     * Refuses the delete when any module uses any of the items.
     *
     * @param  list<string>  $ids
     *
     * @throws CatalogItemInUse
     */
    public function refuseIfUsed(CatalogItem $item, array $ids): void
    {
        $ids = array_values(array_unique(array_map(static fn (string $id): string => strtolower($id), $ids)));

        if ($ids === []) {
            return;
        }

        $uses = [];

        foreach (array_keys($this->usages) as $usage) {
            /** @var CatalogUsage $module */
            $module = $this->container->make($usage);
            array_push($uses, ...$module->usesOf($item, $ids));
        }

        if ($uses !== []) {
            throw new CatalogItemInUse(array_values(array_unique(array_map(static fn (CatalogUseDto $use): string => $use->module, $uses))), count($uses));
        }
    }
}
