<?php

declare(strict_types=1);

namespace Modules\Sales\Application\Query\SalesActions;

/**
 * What a reader may do with one store's orders and returns (sales.md §4.1): each of §3's jobs, held in that
 * store or not. Never what the order's state allows - that is the screen's to show and every handler's to
 * check again.
 */
final readonly class SalesActions
{
    public function __construct(
        public bool $mayApprove,
        public bool $mayEdit,
        public bool $mayCancel,
        public bool $mayFulfil,
        public bool $mayRecordMoney,
        public bool $mayManageReturns,
    ) {}
}
