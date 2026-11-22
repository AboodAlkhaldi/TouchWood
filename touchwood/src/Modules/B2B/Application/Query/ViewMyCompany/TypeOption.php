<?php

declare(strict_types=1);

namespace Modules\B2B\Application\Query\ViewMyCompany;

/**
 * One choice the form offers (b2b.md §1.3): a company type, or a document type. A type staff
 * deactivated to be shown greyed out is offered marked; one deactivated to be hidden is not offered.
 */
final readonly class TypeOption
{
    public function __construct(
        public string $id,
        public string $nameAr,
        public string $nameEn,
        /** Deactivated, shown greyed out: seen, not chosen. */
        public bool $greyed,
        /** Document types only: a file is needed to send. Never for a greyed one. */
        public bool $required,
    ) {}
}
