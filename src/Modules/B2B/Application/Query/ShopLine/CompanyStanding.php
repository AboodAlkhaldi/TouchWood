<?php

declare(strict_types=1);

namespace Modules\B2B\Application\Query\ShopLine;

use Modules\B2B\Public\Enums\CompanyStatus;

/**
 * Where a company account stands, as the line under the shop's header needs it (b2b.md §4.4): the
 * company's status and why, if there is a company, and whether a draft is open.
 */
final readonly class CompanyStanding
{
    public function __construct(
        public ?CompanyStatus $status,
        public ?string $statusReason,
        public bool $draftOpen,
    ) {}
}
