<?php

declare(strict_types=1);

namespace Modules\Catalog\Application\Products;

use Illuminate\Contracts\Container\Container;
use LogicException;
use Modules\Catalog\Public\Contracts\ShopCardFact;
use Modules\Catalog\Public\Contracts\ShopCardFacts;
use Shared\Domain\ValueObject\StoreId;

/**
 * `ShopCardFacts` kept in memory, filled by the modules' service providers (amendment 32).
 */
final class InMemoryShopCardFacts implements ShopCardFacts
{
    /** @var array<string, class-string<ShopCardFact>> */
    private array $facts = [];

    public function __construct(
        private readonly Container $container,
    ) {}

    public function register(string $key, string $fact): void
    {
        if (preg_match('/^[a-z]+\.[a-z_]+$/', $key) !== 1) {
            throw new LogicException("The shop card fact key \"{$key}\" is not {module}.{key}.");
        }

        if (! is_subclass_of($fact, ShopCardFact::class)) {
            throw new LogicException("\"{$fact}\" does not implement ".ShopCardFact::class.'.');
        }

        if (isset($this->facts[$key])) {
            throw new LogicException("The shop card fact \"{$key}\" is registered twice.");
        }

        $this->facts[$key] = $fact;
    }

    /**
     * Every fact about these cards, each module asked once: product id => fact key => what it says. A page
     * with no cards asks nobody.
     *
     * @param  list<string>  $productIds
     * @return array<string, array<string, array<string, mixed>>>
     */
    public function read(StoreId $store, ?string $customerId, array $productIds): array
    {
        $productIds = array_values(array_unique($productIds));

        if ($productIds === []) {
            return [];
        }

        $facts = [];

        foreach ($this->facts as $key => $class) {
            $fact = $this->container->make($class);

            // Checked when it was registered; resolved from the container, so asked again.
            if (! $fact instanceof ShopCardFact) {
                throw new LogicException("\"{$class}\" does not implement ".ShopCardFact::class.'.');
            }

            foreach ($fact->read($store, $customerId, $productIds) as $productId => $said) {
                // Only the page's own cards.
                if (in_array($productId, $productIds, true)) {
                    $facts[$productId][$key] = $said;
                }
            }
        }

        return $facts;
    }
}
