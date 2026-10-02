<?php

declare(strict_types=1);

namespace Modules\B2B\Application\Query\StaffCompanyActions;

/**
 * One listed company type a staff correction may pick (b2b.md §3.2, §4.6). A deactivated one is
 * offered only to someone who may also activate types, and choosing it activates it first
 * (amendments 8(b) and 10(b)).
 */
final readonly class CorrectionChoice
{
    public function __construct(
        public string $id,
        public string $nameAr,
        public string $nameEn,
        public bool $active,
    ) {}
}
