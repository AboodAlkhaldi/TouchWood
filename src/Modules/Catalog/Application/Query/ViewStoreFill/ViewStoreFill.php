<?php

declare(strict_types=1);

namespace Modules\Catalog\Application\Query\ViewStoreFill;

/**
 * A store file's page (catalog.md §1.3; amendment 6(g)).
 */
final readonly class ViewStoreFill
{
    public function __construct(
        public string $importId,
    ) {}
}
