<?php

declare(strict_types=1);

namespace Modules\Catalog\Application\Products;

use Illuminate\Contracts\Container\Container;
use LogicException;
use Modules\Catalog\Public\Contracts\ShopProductSection;
use Modules\Catalog\Public\Contracts\ShopProductSections;
use Modules\Catalog\Public\Dto\ShopProductVisitDto;
use Shared\Domain\ValueObject\StoreId;

/**
 * `ShopProductSections` kept in memory, filled by the modules' service providers (amendment 26(b)).
 */
final class InMemoryShopProductSections implements ShopProductSections
{
    /** @var array<string, class-string<ShopProductSection>> */
    private array $sections = [];

    public function __construct(
        private readonly Container $container,
    ) {}

    public function register(string $key, string $section): void
    {
        if (preg_match('/^[a-z]+\.[a-z_]+$/', $key) !== 1) {
            throw new LogicException("The shop product section key \"{$key}\" is not {module}.{key}.");
        }

        if (! is_subclass_of($section, ShopProductSection::class)) {
            throw new LogicException("\"{$section}\" does not implement ".ShopProductSection::class.'.');
        }

        if (isset($this->sections[$key])) {
            throw new LogicException("The shop product section \"{$key}\" is registered twice.");
        }

        $this->sections[$key] = $section;
    }

    /**
     * Every section's part of this page, in the order registered; a section with nothing to draw
     * carries null.
     *
     * @param  'ar'|'en'  $locale
     * @param  array<string, string>  $after  section key => where it goes on from
     * @return array<string, array<string, mixed>|null>
     */
    public function read(StoreId $store, string $locale, string $productId, ?string $customerId, array $after): array
    {
        $parts = [];
        $visit = new ShopProductVisitDto($store, $locale, $productId, $customerId, $after);

        foreach ($this->sections as $key => $class) {
            $section = $this->container->make($class);

            // Checked when it was registered; resolved from the container, so asked again.
            if (! $section instanceof ShopProductSection) {
                throw new LogicException("\"{$class}\" does not implement ".ShopProductSection::class.'.');
            }

            $parts[$key] = $section->read($visit);
        }

        return $parts;
    }
}
