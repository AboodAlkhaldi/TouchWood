<?php

declare(strict_types=1);

namespace Modules\Sales\Domain\Model;

/**
 * A return as its changes read it (sales.md §1.4): `REQUESTED` → `ACCEPTED`, `REFUSED` or `WITHDRAWN`;
 * `ACCEPTED` → `RECEIVED` → `COMPLETED`, or `CLOSED` when its pieces never came.
 */
final readonly class ReturnState
{
    public const string REQUESTED = 'REQUESTED';

    public const string ACCEPTED = 'ACCEPTED';

    public const string REFUSED = 'REFUSED';

    public const string WITHDRAWN = 'WITHDRAWN';

    public const string RECEIVED = 'RECEIVED';

    public const string COMPLETED = 'COMPLETED';

    public const string CLOSED = 'CLOSED';

    public function __construct(
        public string $id,
        public string $orderId,
        public string $storeId,
        public string $status,
    ) {}
}
