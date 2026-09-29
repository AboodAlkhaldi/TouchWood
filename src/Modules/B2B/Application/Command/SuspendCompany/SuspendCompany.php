<?php

declare(strict_types=1);

namespace Modules\B2B\Application\Command\SuspendCompany;

/**
 * Staff suspending a company, from any status, with a reason (b2b.md §3.2, §4.1).
 */
final readonly class SuspendCompany
{
    public function __construct(
        public string $companyId,
        public string $reason,
    ) {}
}
