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

    /** A `ReportMoney`, or empty where there is no figure (nothing waiting, say). The writers take a text too; no sheet writes one. */
    case Money = 'money';

    /** A day, `Y-m-d`; empty on a total row. */
    case Day = 'day';

    /** A month, `Y-m`; empty on a total row. */
    case Month = 'month';
}
