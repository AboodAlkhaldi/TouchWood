<?php

declare(strict_types=1);

namespace Modules\Catalog\Application\Query\ViewStoreFill;

/**
 * One item of a store file (amendment 6(g)). An open item stands as one of:
 * `READY` — switching it on chooses the variants carrying its code —, `NOT_READY` (what the product
 * lacks, completed in its page), `ARCHIVED`, `ALREADY_ON`, or `UNKNOWN` — a code no product holds,
 * corrected or removed here.
 */
final readonly class StoreFillItemView
{
    public const string READY = 'READY';

    public const string NOT_READY = 'NOT_READY';

    public const string ARCHIVED = 'ARCHIVED';

    public const string ALREADY_ON = 'ALREADY_ON';

    public const string UNKNOWN = 'UNKNOWN';

    /**
     * @param  string|null  $standing  for an open item, where it stands now
     * @param  list<string>  $missing  for one not ready, as `Readiness` names them
     */
    public function __construct(
        public string $id,
        public int $number,
        public string $code,
        public string $price,
        public ?int $stock,
        public string $state,
        public ?string $standing,
        public ?string $productId,
        public array $missing,
    ) {}
}
