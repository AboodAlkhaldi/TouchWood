<?php

declare(strict_types=1);

namespace Modules\Content\Presentation\Http\Controller;

use Illuminate\Http\RedirectResponse;
use Modules\Content\Application\Query\CampaignOfRun\CampaignOfRunHandler;

/**
 * `GET /admin/content/campaigns/runs/{run}`, the link S8's alert carries (ops.md §9.7 #3): it lands on the run's
 * campaign page (content.md W4b), `/admin/content/campaigns/{id}`, whose screen is PR 8's. The handler checks the
 * campaign's job in the run's store; this checks nothing (handoff §19).
 */
final readonly class CampaignRunController
{
    public function __invoke(string $run, CampaignOfRunHandler $handler): RedirectResponse
    {
        return redirect('/admin/content/campaigns/'.$handler->handle($run));
    }
}
