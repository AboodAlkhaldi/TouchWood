<?php

declare(strict_types=1);

namespace Modules\Catalog\Application\Query\Shop;

/**
 * One line of a variant's details on its page (catalog.md §1.7): an informational attribute's name
 * and the variant's value, in the page's language, with its unit. `measure` says the value is a number
 * kept with its attribute's unit («35 kg»), which the specifications table draws in the mono face, left
 * to right; typed text never is (flags-2026-11.md S2.1, F7; owner, 2026-11-20).
 */
final readonly class VariantDetail
{
    public function __construct(
        public string $name,
        public string $value,
        public bool $measure,
    ) {}
}
