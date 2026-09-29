<?php

declare(strict_types=1);

namespace Modules\B2B\Application\Command\ApproveCompany;

/**
 * Staff approving the application a company sent (b2b.md §3.2): an optional note, emailed to the
 * customer. When the application's type was deactivated since it was sent, which type the company
 * keeps — and for a correction, the type itself (amendment 10(e)).
 */
final readonly class ApproveCompany
{
    public function __construct(
        public string $companyId,
        public ?string $note = null,
        public ?ApprovalTypeChoice $typeChoice = null,
        public ?string $correctTypeId = null,
        public ?string $correctOther = null,
        public bool $confirmReactivation = false,
    ) {}
}
