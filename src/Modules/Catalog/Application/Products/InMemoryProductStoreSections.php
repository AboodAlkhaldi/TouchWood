<?php

declare(strict_types=1);

namespace Modules\Catalog\Application\Products;

use Illuminate\Contracts\Container\Container;
use LogicException;
use Modules\Catalog\Public\Contracts\ProductStoreSection;
use Modules\Catalog\Public\Contracts\ProductStoreSections;

/**
 * **The product page's store sections** (catalog.md amendment 23(e)), collected from the service
 * providers of the modules above Catalog, as the import's sections are (`InMemoryImportSections`). The
 * classes are resolved only when a product's Stores tab is read, so registering costs nothing on any
 * other request; and resolved each time, so one may depend on the person reading.
 */
final class InMemoryProductStoreSections implements ProductStoreSections
{
    /** A component's path under resources/js/sections/: folders and a name, each a capitalised word. */
    private const string COMPONENT = '/\A[A-Z][A-Za-z0-9]*(\/[A-Z][A-Za-z0-9]*)+\z/';

    /** @var array<class-string<ProductStoreSection>, string> class => registering module */
    private array $sections = [];

    public function __construct(
        private readonly Container $container,
    ) {}

    /**
     * @param  class-string  $section
     */
    public function register(string $module, string $section): void
    {
        if (! is_subclass_of($section, ProductStoreSection::class)) {
            throw new LogicException("Module \"{$module}\" registered \"{$section}\", which does not implement ".ProductStoreSection::class.'.');
        }

        if (isset($this->sections[$section])) {
            throw new LogicException("\"{$section}\" is already registered by module \"{$this->sections[$section]}\".");
        }

        $this->sections[$section] = $module;
    }

    /**
     * @return list<array{module: string, section: ProductStoreSection}> in the order they were registered
     *
     * @throws LogicException for a section naming a component that is not a path under resources/js/sections/
     */
    public function all(): array
    {
        $all = [];

        foreach ($this->sections as $class => $module) {
            $section = $this->container->make($class);

            if (preg_match(self::COMPONENT, $section->component()) !== 1) {
                throw new LogicException("\"{$class}\" names its component \"{$section->component()}\", which is not a path under resources/js/sections/ such as \"Pricing/ProductPrices\".");
            }

            $all[] = ['module' => $module, 'section' => $section];
        }

        return $all;
    }
}
