<?php

declare(strict_types=1);

namespace Modules\B2B\Application\Command\ReinstateCompany;

/**
 * Staff ending a suspension, with a reason (b2b.md §3.2, §4.1).
 */
final readonly class ReinstateCompany
{
    public function __construct(
        public string $companyId,
        public string $reason,
    ) {}
}
