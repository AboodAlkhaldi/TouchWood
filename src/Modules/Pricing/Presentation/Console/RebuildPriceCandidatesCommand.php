<?php

declare(strict_types=1);

namespace Modules\Pricing\Presentation\Console;

use Illuminate\Console\Command;
use Modules\Pricing\Application\Command\RebuildPriceCandidates\RebuildPriceCandidates;
use Modules\Pricing\Application\Command\RebuildPriceCandidates\RebuildPriceCandidatesHandler;

/**
 * The candidates' repair, run by hand (pricing.md §3): whenever a size's prices may not match what
 * they are made of.
 */
final class RebuildPriceCandidatesCommand extends Command
{
    public const string NAME = 'pricing:rebuild-candidates';

    protected $signature = self::NAME;

    protected $description = "Write every size's price candidates again, in every store, and tell Catalog its prices";

    public function handle(RebuildPriceCandidatesHandler $handler): int
    {
        $outOfStep = $handler->handle(new RebuildPriceCandidates);

        $this->info("The candidates were written again; {$outOfStep} sizes were out of step.");

        return self::SUCCESS;
    }
}
