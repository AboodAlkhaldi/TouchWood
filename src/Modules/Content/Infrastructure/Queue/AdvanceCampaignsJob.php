<?php

declare(strict_types=1);

namespace Modules\Content\Infrastructure\Queue;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Modules\Content\Application\Command\AdvanceCampaigns\AdvanceCampaigns;
use Modules\Content\Application\Command\AdvanceCampaigns\AdvanceCampaignsHandler;

/**
 * Every minute, from one server (content.md §1.7 #9; Platform's pattern for the outbox relay): campaign runs going
 * live, ending, and their ending alerts. Two copies at once, or a retry, fire nothing twice - every change is a
 * conditional update - so the job needs no lock of its own. Named at `content::jobs.advance_campaigns`.
 */
final class AdvanceCampaignsJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    /** One try: the next minute's tick takes whatever this one left, and a failure waits on the failed jobs screen. */
    public int $tries = 1;

    /** Well inside the minute's tick. */
    public int $timeout = 50;

    public function handle(AdvanceCampaignsHandler $handler): void
    {
        $handler->handle(new AdvanceCampaigns);
    }
}
