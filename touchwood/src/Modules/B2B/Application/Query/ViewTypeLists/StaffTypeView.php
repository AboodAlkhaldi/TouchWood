<?php

declare(strict_types=1);

namespace Modules\B2B\Application\Query\ViewTypeLists;

/**
 * One row of a store's type list, as staff manage it (b2b.md §1.3, §4.6).
 */
final readonly class StaffTypeView
{
    public function __construct(
        public string $id,
        public string $nameAr,
        public string $nameEn,
        public int $position,
        public bool $active,
        /** HIDDEN or GREYED while deactivated; null while active (amendment 5). */
        public ?string $inactiveDisplay,
        /** Document types: a file is needed to send. Null for a company type. */
        public ?bool $required,
        /** Company types: how many companies hold it now. Null for a document type. */
        public ?int $holders,
    ) {}
}
