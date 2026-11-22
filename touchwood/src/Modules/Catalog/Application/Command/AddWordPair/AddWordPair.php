<?php

declare(strict_types=1);

namespace Modules\Catalog\Application\Command\AddWordPair;

/**
 * Two words a shopper means the same by — "مفصلة" and "hinge" (catalog.md §1.11), in either order.
 */
final readonly class AddWordPair
{
    public function __construct(
        public string $one,
        public string $other,
    ) {}
}
