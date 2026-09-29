<?php

declare(strict_types=1);

namespace Modules\B2B\Application\Command\DeactivateCompanyType;

use Modules\B2B\Domain\ValueObject\InactiveTypeDisplay;

/**
 * Staff deactivating a company type (b2b.md §1.3, §3.2, amendment 5): how it shows to new
 * applications — hidden or greyed out — and whether the companies holding it are **left** with it
 * (no replacement) or **replaced** with another active type of the same store.
 */
final readonly class DeactivateCompanyType
{
    public function __construct(
        public string $typeId,
        public InactiveTypeDisplay $shown,
        public ?string $replacementTypeId = null,
    ) {}
}
