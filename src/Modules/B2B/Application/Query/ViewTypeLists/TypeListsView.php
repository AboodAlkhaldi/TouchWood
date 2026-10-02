<?php

declare(strict_types=1);

namespace Modules\B2B\Application\Query\ViewTypeLists;

/**
 * One store's list of one kind, with the store's "copied, not yet reviewed" notice (§1.3, amendment
 * 6(a)) and what the reader may do to it.
 */
final readonly class TypeListsView
{
    /**
     * @param  list<StaffTypeView>  $types  in the form's order: position, then the English name
     */
    public function __construct(
        public string $storeId,
        public string $kind,
        public bool $copiedNotReviewed,
        public array $types,
        public TypeListActions $actions,
    ) {}
}
