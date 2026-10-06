<?php

declare(strict_types=1);

namespace Modules\Catalog\Application\Command\DecideImportNames;

/**
 * The Super Admin's decisions on names a products file uses that the catalog lacks (catalog.md §1.12,
 * page part 1): one name, or several together.
 */
final readonly class DecideImportNames
{
    /**
     * @param  array<array-key, mixed>  $decisions  as the page sends them, each `name_id` and `decision` —
     *                                              `EXISTING` with `target_id`, `CREATE` with `name_ar`
     *                                              and `name_en`, or `REFUSE`
     */
    public function __construct(
        public string $importId,
        public array $decisions,
    ) {}
}
