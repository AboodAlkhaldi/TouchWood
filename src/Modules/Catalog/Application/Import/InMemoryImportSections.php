<?php

declare(strict_types=1);

namespace Modules\Catalog\Application\Import;

use Illuminate\Contracts\Container\Container;
use LogicException;
use Modules\Catalog\Public\Contracts\ImportSection;
use Modules\Catalog\Public\Contracts\ImportSections;

/**
 * **The import's sections** (catalog.md §2.3), collected from the service providers of the modules
 * above Catalog — Pricing the prices, Inventory the stock (stage 5) — as Platform collects its
 * `MediaUsages`. The classes are resolved only when a store file is read or switched on, so
 * registering costs nothing on other requests.
 */
final class InMemoryImportSections implements ImportSections
{
    /** @var array<class-string<ImportSection>, string> class => registering module */
    private array $sections = [];

    public function __construct(
        private readonly Container $container,
    ) {}

    public function register(string $module, string $section): void
    {
        if (! is_subclass_of($section, ImportSection::class)) {
            throw new LogicException("Module \"{$module}\" registered \"{$section}\", which does not implement ".ImportSection::class.'.');
        }

        if (isset($this->sections[$section])) {
            throw new LogicException("\"{$section}\" is already registered by module \"{$this->sections[$section]}\".");
        }

        $this->sections[$section] = $module;
    }

    /**
     * @return list<ImportSection> in the order they were registered
     */
    public function all(): array
    {
        return array_map(
            fn (string $section): ImportSection => $this->container->make($section),
            array_keys($this->sections),
        );
    }
}
