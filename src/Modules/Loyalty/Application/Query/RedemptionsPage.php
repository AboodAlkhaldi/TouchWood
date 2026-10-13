<?php

declare(strict_types=1);

namespace Modules\Loyalty\Application\Query;

use DateTimeImmutable;

/** A page of a store's redemptions, newest first, with the cursor to the next (null at the end). */
final readonly class RedemptionsPage
{
    /**
     * @param  list<RedemptionRow>  $rows
     */
    public function __construct(
        public array $rows,
        public ?DateTimeImmutable $nextAt,
        public ?int $nextId,
    ) {}
}
