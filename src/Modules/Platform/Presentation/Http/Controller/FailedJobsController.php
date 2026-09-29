<?php

declare(strict_types=1);

namespace Modules\Platform\Presentation\Http\Controller;

use App\Http\FormErrors;
use App\Http\Page;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Response;
use Modules\Platform\Application\Command\DeleteFailedJob\DeleteFailedJob;
use Modules\Platform\Application\Command\DeleteFailedJob\DeleteFailedJobHandler;
use Modules\Platform\Application\Command\RetryFailedJob\RetryFailedJob;
use Modules\Platform\Application\Command\RetryFailedJob\RetryFailedJobHandler;
use Modules\Platform\Application\Query\ListFailedJobs\ListFailedJobsHandler;
use Modules\Platform\Application\Query\ViewFailedJob\ViewFailedJobHandler;
use Modules\Platform\Presentation\Http\Resource\FailedJobPages;
use Shared\Domain\Error\DomainError;

/**
 * The failed jobs screen (frontend.md E7): the list, one job with its whole error, and a retry or a
 * delete of one job at a time. Each handler asks for platform.jobs.manage, admin-only; the routing
 * only opens the door.
 *
 * Retrying or deleting from a job's own page leaves nothing to come back to, so both land on the
 * list, which says what was done.
 */
final readonly class FailedJobsController
{
    /** @var list<string> */
    private const array WORDS = ['platform::admin_failed_jobs', 'platform::errors', 'access::errors', 'admin'];

    public function __construct(
        private Page $page,
        private FailedJobPages $pages,
    ) {}

    public function index(ListFailedJobsHandler $handler): Response
    {
        return $this->page->render('Platform/Admin/FailedJobs/Index', $this->pages->list($handler)->toArray(), self::WORDS);
    }

    public function show(string $job, ViewFailedJobHandler $handler): Response
    {
        return $this->page->render('Platform/Admin/FailedJobs/Show', $this->pages->view($handler, $job)->toArray(), self::WORDS);
    }

    public function retry(Request $request, string $job, RetryFailedJobHandler $handler): RedirectResponse
    {
        try {
            $handler->handle(new RetryFailedJob($job));
        } catch (DomainError $error) {
            return FormErrors::back($request, $error);
        }

        return redirect()->route('platform.admin.failed_jobs')->with('status', __('platform::admin_failed_jobs.retried'));
    }

    public function delete(Request $request, string $job, DeleteFailedJobHandler $handler): RedirectResponse
    {
        try {
            $handler->handle(new DeleteFailedJob($job));
        } catch (DomainError $error) {
            return FormErrors::back($request, $error);
        }

        return redirect()->route('platform.admin.failed_jobs')->with('status', __('platform::admin_failed_jobs.deleted'));
    }
}
