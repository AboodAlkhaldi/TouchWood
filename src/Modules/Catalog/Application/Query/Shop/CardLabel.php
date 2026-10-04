<?php

declare(strict_types=1);

namespace Modules\Catalog\Application\Query\Shop;

/**
 * A label on a card, in the page's language, drawn with Geist's Badge in its tone (catalog.md §1.8).
 */
final readonly class CardLabel
{
    public function __construct(
        public string $name,
        public string $tone,
    ) {}
}
