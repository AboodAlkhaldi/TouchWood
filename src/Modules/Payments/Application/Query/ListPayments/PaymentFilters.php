<?php

declare(strict_types=1);

namespace Modules\Payments\Application\Query\ListPayments;

/**
 * The list's filters, checked. `stores` null: every store.
 */
final readonly class PaymentFilters
{
    /**
     * @param  list<string>|null  $stores
     */
    public function __construct(
        public ?array $stores,
        public ?string $state,
        public ?string $mode,
    ) {}
}
