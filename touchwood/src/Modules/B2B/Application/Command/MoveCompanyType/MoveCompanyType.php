<?php

declare(strict_types=1);

namespace Modules\B2B\Application\Command\MoveCompanyType;

/**
 * Staff changing where a company type sits on the form (b2b.md §1.3, §3.2): 0 to 10,000.
 */
final readonly class MoveCompanyType
{
    public function __construct(
        public string $typeId,
        public int $position,
    ) {}
}
