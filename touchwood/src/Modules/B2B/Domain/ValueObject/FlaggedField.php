<?php

declare(strict_types=1);

namespace Modules\B2B\Domain\ValueObject;

/**
 * A field of a sent application that staff may mark when rejecting it (b2b.md §1.2, §5,
 * amendment 4): the next draft cannot be sent until it holds a different value. The five values
 * are the table's own list.
 */
enum FlaggedField: string
{
    case Name = 'name';
    case CompanyType = 'company_type';
    case CrNumber = 'cr_number';
    case TaxNumber = 'tax_number';
    case Address = 'address';
}
