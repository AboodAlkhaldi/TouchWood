<?php

declare(strict_types=1);

namespace Modules\B2B\Application\Command\SubmitApplication;

/**
 * Sends the signed-in company account's open draft (b2b.md §3.1, §4.1), and whether the store's Company Terms were
 * ticked - asked only while the store has them published (flags-2026-11.md S3.6).
 */
final readonly class SubmitApplication
{
    public function __construct(
        public bool $companyTermsAccepted = false,
        /** The Company Terms the form showed, by id and version: refused unless they are the store's now (owner, 2026-11-20). */
        public ?string $shownCompanyTermsId = null,
        public ?int $shownCompanyTermsVersion = null,
    ) {}
}
