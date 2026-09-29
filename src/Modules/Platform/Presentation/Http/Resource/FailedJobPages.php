<?php

declare(strict_types=1);

namespace Modules\Platform\Presentation\Http\Resource;

use DateTimeImmutable;
use Illuminate\Contracts\Foundation\Application;
use Modules\Platform\Application\FailedJobs\FailedJob;
use Modules\Platform\Application\Query\ListFailedJobs\FailedJobRow;
use Modules\Platform\Application\Query\ListFailedJobs\ListFailedJobs;
use Modules\Platform\Application\Query\ListFailedJobs\ListFailedJobsHandler;
use Modules\Platform\Application\Query\ViewFailedJob\ViewFailedJob;
use Modules\Platform\Application\Query\ViewFailedJob\ViewFailedJobHandler;
use Modules\Platform\Domain\Exception\FailedJobNotFound;

/**
 * The failed jobs, in the shape E7 wants (frontend.md 3.5). It decides nothing — the handlers answer
 * who may see what; this names each job in its module's words (FailedJobNames).
 */
final readonly class FailedJobPages
{
    public function __construct(
        private Application $app,
    ) {}

    public function list(ListFailedJobsHandler $handler): FailedJobsPage
    {
        return new FailedJobsPage(array_map(
            fn (FailedJobRow $row): FailedJobRowData => $this->row($row->id, $row->className, $row->failedAt, $row->triesAllowed, $row->queue, $row->errorLine),
            $handler->handle(new ListFailedJobs),
        ));
    }

    /**
     * @throws FailedJobNotFound
     */
    public function view(ViewFailedJobHandler $handler, string $id): FailedJobPage
    {
        $job = $handler->handle(new ViewFailedJob($id));

        return new FailedJobPage($this->fromJob($job), $job->error);
    }

    private function fromJob(FailedJob $job): FailedJobRowData
    {
        return $this->row($job->id, $job->className(), $job->failedAt, $job->triesAllowed(), $job->queue, $job->errorLine());
    }

    private function row(string $id, string $class, DateTimeImmutable $failedAt, ?int $tries, string $queue, string $errorLine): FailedJobRowData
    {
        return new FailedJobRowData($id, $this->name($class), $failedAt->format(DATE_ATOM), $tries, $queue, $errorLine);
    }

    private function name(string $class): string
    {
        $key = FailedJobNames::keyFor($class);

        if ($key === null) {
            return $class;
        }

        $name = __($key, [], $this->app->getLocale() === 'en' ? 'en' : 'ar');

        return is_string($name) && $name !== $key ? $name : $class;
    }
}
