<?php

declare(strict_types=1);

namespace Modules\Catalog\Application\Command\CorrectStoreFillCode;

/**
 * An item's code mended on a store file's page — a typo, or a miss (catalog.md §1.3; amendment 6(g)).
 */
final readonly class CorrectStoreFillCode
{
    public function __construct(
        public string $importId,
        public string $itemId,
        public string $code,
    ) {}
}
