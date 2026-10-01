<?php

declare(strict_types=1);

namespace Modules\B2B\Application\Command\CorrectCompanyType;

/**
 * Staff putting a company's type right (b2b.md §3.2): exactly one of a listed type of its home store
 * or "Other" in better words. A deactivated type needs `confirmReactivation` — the screen first says
 * the type becomes active again (amendment 8(b)).
 */
final readonly class CorrectCompanyType
{
    public function __construct(
        public string $companyId,
        public ?string $typeId = null,
        public ?string $other = null,
        public bool $confirmReactivation = false,
    ) {}
}
