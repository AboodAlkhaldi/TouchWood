<?php

declare(strict_types=1);

namespace Modules\Catalog\Application\Products;

use Illuminate\Contracts\Container\Container;
use LogicException;
use Modules\Catalog\Public\Contracts\ShopListingSlot;
use Modules\Catalog\Public\Contracts\ShopListingSlots;
use Modules\Catalog\Public\Dto\ShopListingVisitDto;

/**
 * `ShopListingSlots`, kept as class names and resolved when asked, so a slot may depend on the request it answers
 * (catalog.md amendment 41(a)).
 */
final class InMemoryShopListingSlots implements ShopListingSlots
{
    /** @var array<string, class-string<ShopListingSlot>> module => class */
    private array $slots = [];

    public function __construct(
        private readonly Container $container,
    ) {}

    public function register(string $module, string $slot): void
    {
        if (! is_subclass_of($slot, ShopListingSlot::class)) {
            throw new LogicException("Module \"{$module}\" registered \"{$slot}\", which does not implement ".ShopListingSlot::class.'.');
        }

        if (isset($this->slots[$module])) {
            throw new LogicException("Module \"{$module}\" already has a slot above the shop's lists, \"{$this->slots[$module]}\".");
        }

        $this->slots[$module] = $slot;
    }

    /**
     * Every slot's answer, in the order registered, by module; a slot with nothing to draw is left out.
     *
     * @return array<string, array<string, mixed>>
     */
    public function read(ShopListingVisitDto $visit): array
    {
        $read = [];

        foreach ($this->slots as $module => $class) {
            $slot = $this->container->make($class);

            // Checked when it was registered; resolved from the container, so asked again.
            if (! $slot instanceof ShopListingSlot) {
                throw new LogicException("\"{$class}\" does not implement ".ShopListingSlot::class.'.');
            }

            $answer = $slot->read($visit);

            if ($answer !== null) {
                $read[$module] = $answer;
            }
        }

        return $read;
    }
}
