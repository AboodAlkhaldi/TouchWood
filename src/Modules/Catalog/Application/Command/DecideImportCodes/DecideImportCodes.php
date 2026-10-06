<?php

declare(strict_types=1);

namespace Modules\Catalog\Application\Command\DecideImportCodes;

/**
 * The Super Admin's decisions on products of a file whose codes the catalog already has (catalog.md
 * §1.12, page part 2): one product, or several together.
 */
final readonly class DecideImportCodes
{
    /**
     * @param  array<array-key, mixed>  $decisions  as the page sends them, each `product_id` (the
     *                                              import's product) and `decision` — `UPDATE`, `REPLACE`,
     *                                              `SKIP`, or `RECODE` with `new_codes`: each code the
     *                                              catalog has → its new one
     */
    public function __construct(
        public string $importId,
        public array $decisions,
    ) {}
}
