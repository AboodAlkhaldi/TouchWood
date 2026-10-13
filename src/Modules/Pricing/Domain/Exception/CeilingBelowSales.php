<?php

declare(strict_types=1);

namespace Modules\Pricing\Domain\Exception;

use Shared\Domain\Error\ErrorCategory;

/**
 * A lower discount ceiling held back (pricing.md §1.12, §7; owner, 2026-10-13): sales running or
 * scheduled go deeper than it. The note names them, to change or end first.
 */
final class CeilingBelowSales extends PricingError
{
    /**
     * @param  list<string>  $sales
     */
    public function __construct(public readonly array $sales = [])
    {
        parent::__construct('Sales running or scheduled go deeper than this ceiling.');
    }

    public function type(): string
    {
        return 'pricing.ceiling_below_sales';
    }

    public function category(): ErrorCategory
    {
        return ErrorCategory::Conflict;
    }

    public function context(): array
    {
        return ['sales' => implode(',', $this->sales)];
    }
}
