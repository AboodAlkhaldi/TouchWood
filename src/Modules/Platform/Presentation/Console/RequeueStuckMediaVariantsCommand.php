<?php

declare(strict_types=1);

namespace Modules\Platform\Presentation\Console;

use Illuminate\Console\Command;
use Modules\Platform\Application\Command\RequeueStuckMediaVariants\RequeueStuckMediaVariants;
use Modules\Platform\Application\Command\RequeueStuckMediaVariants\RequeueStuckMediaVariantsHandler;

/**
 * The sweep, run by hand. The scheduler runs it as a queued job instead
 * (RequeueStuckMediaVariantsJob), so that its audit source is JOB.
 */
final class RequeueStuckMediaVariantsCommand extends Command
{
    public const string NAME = 'platform:media:requeue-stuck';

    protected $signature = self::NAME;

    protected $description = 'Queue variant generation again for images stuck in PENDING';

    public function handle(RequeueStuckMediaVariantsHandler $handler): int
    {
        $count = $handler->handle(new RequeueStuckMediaVariants);

        $this->info("{$count} stuck image(s) queued again.");

        return self::SUCCESS;
    }
}
