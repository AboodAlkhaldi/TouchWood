<?php

declare(strict_types=1);

namespace Modules\B2B\Application\Query\ShopLine;

use Modules\B2B\Public\Enums\CompanyStatus;

/**
 * Where a company account stands **in one store**, as the line under the shop's header needs it
 * (b2b.md §4.4, amendment 19(c)): that store's company's status and why, if there is one, whether a
 * draft is open there, and whether the account has a company in another store — then the line offers
 * applying in this one.
 */
final readonly class CompanyStanding
{
    public function __construct(
        public ?CompanyStatus $status,
        public ?string $statusReason,
        public bool $draftOpen,
        public bool $elsewhere = false,
    ) {}
}
