<?php

declare(strict_types=1);

namespace Modules\B2B\Application\Command\UpdateCompanyContact;

/**
 * The company's registered address (b2b.md §1.1, §3.1). The responsible person and their phone are
 * the account holder's, kept by Access, so the address is all this changes.
 */
final readonly class UpdateCompanyContact
{
    public function __construct(
        public string $address,
    ) {}
}
