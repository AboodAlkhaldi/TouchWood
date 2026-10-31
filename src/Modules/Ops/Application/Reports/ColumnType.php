<?php

declare(strict_types=1);

namespace Modules\Ops\Application\Reports;

/**
 * What a report's column holds, so a sheet writes it as Excel should hold it: a number as a number,
 * money with its currency's decimals, a day as a date.
 */
enum ColumnType: string
{
    /** A text; a cell may be empty. */
    case Text = 'text';

    /** A whole number. */
    case Count = 'count';

    /** A `ReportMoney`; or a text where the figure waits for a module (VAT of refunds), or empty. */
    case Money = 'money';

    /** A day, `Y-m-d`; empty on a total row. */
    case Day = 'day';

    /** A month, `Y-m`; empty on a total row. */
    case Month = 'month';
}
