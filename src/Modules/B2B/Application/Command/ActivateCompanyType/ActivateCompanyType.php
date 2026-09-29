<?php

declare(strict_types=1);

namespace Modules\B2B\Application\Command\ActivateCompanyType;

/**
 * Staff offering a deactivated company type again (b2b.md §3.2, amendment 10(c)).
 */
final readonly class ActivateCompanyType
{
    public function __construct(
        public string $typeId,
    ) {}
}
