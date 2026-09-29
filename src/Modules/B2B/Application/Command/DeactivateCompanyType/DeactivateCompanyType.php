<?php

declare(strict_types=1);

namespace Modules\B2B\Application\Command\DeactivateCompanyType;

use Modules\B2B\Domain\ValueObject\InactiveTypeDisplay;

/**
 * Staff deactivating a company type (b2b.md §1.3, §3.2, amendments 5 and 11): how it shows to new
 * applications — hidden or greyed out — and what happens to the companies holding it, decided once,
 * here: **left** with it (neither a replacement nor a new type), **replaced** with another active type
 * of the same store, or **replaced with a new type** created in the same step (its names, and its
 * position — the old type's if none is given).
 */
final readonly class DeactivateCompanyType
{
    public function __construct(
        public string $typeId,
        public InactiveTypeDisplay $shown,
        public ?string $replacementTypeId = null,
        public ?string $newTypeNameAr = null,
        public ?string $newTypeNameEn = null,
        public ?int $newTypePosition = null,
    ) {}
}
