<?php

declare(strict_types=1);

namespace Modules\Ops\Application\Reports;

/**
 * A sheet's total row (ops.md §1.10): one value per column, as a row's, and marked as a total by its type -
 * never by its words, since a store may be called anything. The file writes it in its place, after the rows;
 * the preview carries it apart from them, past the rows it shows (§4.6).
 */
final readonly class ReportTotal
{
    /**
     * @param  list<string|int|ReportMoney|null>  $values
     */
    public function __construct(
        public array $values,
    ) {}
}
