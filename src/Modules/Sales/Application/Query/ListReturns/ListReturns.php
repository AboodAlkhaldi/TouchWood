<?php

declare(strict_types=1);

namespace Modules\Sales\Application\Query\ListReturns;

final readonly class ListReturns
{
    public function __construct(
        /** One store; null every store the reader covers. */
        public ?string $storeId = null,
        /** One state (REQUESTED, ACCEPTED, …); null every state. */
        public ?string $status = null,
        /** The id of the last return shown. */
        public ?string $after = null,
    ) {}
}
