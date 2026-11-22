<?php

declare(strict_types=1);

namespace Modules\Catalog\Infrastructure\Queue;

use Modules\Catalog\Application\Import\ImportQueue;

final class LaravelImportQueue implements ImportQueue
{
    public function bringIn(string $importId): void
    {
        BringInImportJob::dispatch($importId)->afterCommit();
    }
}
