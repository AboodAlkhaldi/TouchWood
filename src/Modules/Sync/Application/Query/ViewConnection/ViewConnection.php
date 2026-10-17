<?php

declare(strict_types=1);

namespace Modules\Sync\Application\Query\ViewConnection;

/**
 * A store's Provider page (sync.md §1.2, §1.8).
 */
final readonly class ViewConnection
{
    public function __construct(
        public string $storeId,
    ) {}
}
