<?php

declare(strict_types=1);

namespace Modules\B2B\Application\Command\ApproveCompany;

/**
 * Staff approving the application a company sent (b2b.md §3.2): an optional note, emailed to the
 * customer. No choice about the type (amendment 11(a)).
 */
final readonly class ApproveCompany
{
    public function __construct(
        public string $companyId,
        public ?string $note = null,
    ) {}
}
