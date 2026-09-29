<?php

declare(strict_types=1);

namespace Modules\B2B\Application\Events;

use Carbon\CarbonImmutable;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Support\Str;
use Modules\B2B\Domain\Model\Application;
use Modules\B2B\Domain\Model\Company;
use Modules\B2B\Public\Enums\CompanyStatus;
use Modules\B2B\Public\Events\CompanyApplicationSubmitted;
use Modules\B2B\Public\Events\CompanyStatusChanged;

/**
 * What B2B tells other modules (b2b.md §6), called inside the use case's transaction: both events
 * wait for its commit, so a change rolled back — or retried — tells nobody.
 */
final readonly class CompanyEvents
{
    public function __construct(
        private Dispatcher $events,
    ) {}

    /**
     * After the change: the company's status now, and the reason it now carries.
     */
    public function statusChanged(Company $company, ?CompanyStatus $from): void
    {
        $this->events->dispatch(new CompanyStatusChanged(
            (string) Str::uuid(),
            $company->customerId(),
            $company->id(),
            $from,
            $company->status(),
            $company->statusReason()?->value,
            CarbonImmutable::now(),
        ));
    }

    public function submitted(Company $company, Application $application): void
    {
        $this->events->dispatch(new CompanyApplicationSubmitted((string) Str::uuid(), $company->id(), $application->id(), CarbonImmutable::now()));
    }
}
