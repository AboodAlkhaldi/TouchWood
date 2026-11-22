<?php

declare(strict_types=1);

namespace Modules\Catalog\Public\Contracts;

/**
 * What other modules add to the shop's product cards (catalog.md amendment 32), registered in their service
 * providers. Each page that shows cards carries `cardFacts` — product id => `{module}.{key}` => the fact —
 * beside its cards; Catalog never knows what is in them.
 */
interface ShopCardFacts
{
    /**
     * @param  string  $key  `{module}.{key}` — "feedback.saved"
     * @param  class-string  $fact  a class implementing ShopCardFact, resolved from the container on each request
     *
     * @throws \LogicException for a class that is not a ShopCardFact, a key not of that form, or a key registered twice
     */
    public function register(string $key, string $fact): void;
}
