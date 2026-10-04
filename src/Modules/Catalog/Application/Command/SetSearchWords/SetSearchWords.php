<?php

declare(strict_types=1);

namespace Modules\Catalog\Application\Command\SetSearchWords;

/**
 * A product's extra search words, sent whole (catalog.md §1.1): at most 30, in either language.
 */
final readonly class SetSearchWords
{
    /**
     * @param  array<array-key, mixed>  $words  as typed
     */
    public function __construct(
        public string $productId,
        public array $words,
    ) {}
}
