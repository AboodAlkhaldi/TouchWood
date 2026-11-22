<?php

declare(strict_types=1);

namespace Modules\Sales\Domain\Exception;

use Shared\Domain\Error\ErrorCategory;

/**
 * A line not orderable here: not sold, in its mode, unpriced, outside its minimum and maximum, or not
 * enough stock (§1.1, §1.2) — the sizes named, so the cart can mark them; none for an empty cart.
 */
final class NotOrderable extends SalesError
{
    /**
     * @param  list<string>  $variantIds
     */
    public function __construct(public readonly array $variantIds = [])
    {
        parent::__construct('A line cannot be ordered as it is.');
    }

    public function type(): string
    {
        return 'sales.not_orderable';
    }

    public function category(): ErrorCategory
    {
        return ErrorCategory::Invalid;
    }

    public function context(): array
    {
        return ['variants' => implode(',', $this->variantIds)];
    }
}
