<?php

declare(strict_types=1);

namespace Modules\Catalog\Presentation\Console;

use Illuminate\Console\Command;
use Modules\Catalog\Application\Command\RebuildListing\RebuildListing;
use Modules\Catalog\Application\Command\RebuildListing\RebuildListingHandler;

/**
 * The listing's repair, run by hand (catalog.md §3): after a change of CDN address, or whenever the
 * rows a shopper reads may not match the products.
 */
final class RebuildListingCommand extends Command
{
    public const string NAME = 'catalog:listing:rebuild';

    protected $signature = self::NAME;

    protected $description = "Write every row of the catalog's listing again from the products";

    public function handle(RebuildListingHandler $handler): int
    {
        $handler->handle(new RebuildListing);

        $this->info('The listing was written again.');

        return self::SUCCESS;
    }
}
