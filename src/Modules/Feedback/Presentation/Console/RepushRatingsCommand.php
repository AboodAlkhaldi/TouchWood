<?php

declare(strict_types=1);

namespace Modules\Feedback\Presentation\Console;

use Illuminate\Console\Command;
use Modules\Feedback\Application\Command\RepushRatings\RepushRatings;
use Modules\Feedback\Application\Command\RepushRatings\RepushRatingsHandler;

/** The ratings repair, run by hand (feedback.md §1.2): whenever Catalog's lists may not match Feedback. */
final class RepushRatingsCommand extends Command
{
    public const string NAME = 'feedback:ratings:repush';

    protected $signature = self::NAME;

    protected $description = 'Tell Catalog again every store\'s ratings switch and every product\'s rating';

    public function handle(RepushRatingsHandler $handler): int
    {
        $told = $handler->handle(new RepushRatings);

        $this->info("Catalog was told again. Stores: {$told['stores']}. Products: {$told['products']}.");

        return self::SUCCESS;
    }
}
