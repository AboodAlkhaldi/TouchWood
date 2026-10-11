<?php

declare(strict_types=1);

namespace Modules\Inventory\Presentation\Console;

use Illuminate\Console\Command;
use Modules\Inventory\Application\Command\RebuildOrderable\RebuildOrderable;
use Modules\Inventory\Application\Command\RebuildOrderable\RebuildOrderableHandler;

/**
 * The orderability repair, run by hand (inventory.md §3): whenever Catalog's facts may not match the
 * stock.
 */
final class RebuildOrderableCommand extends Command
{
    public const string NAME = 'inventory:orderable:rebuild';

    protected $signature = self::NAME;

    protected $description = 'Tell Catalog again, in every store, whether each size can be ordered and is ending soon';

    public function handle(RebuildOrderableHandler $handler): int
    {
        $told = $handler->handle(new RebuildOrderable);

        $this->info("Catalog was told again. Sizes told: {$told}.");

        return self::SUCCESS;
    }
}
