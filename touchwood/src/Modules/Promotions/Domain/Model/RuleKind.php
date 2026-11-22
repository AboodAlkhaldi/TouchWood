<?php

declare(strict_types=1);

namespace Modules\Promotions\Domain\Model;

/** A coupon rule includes or excludes (promotions.md §1.2); exclusions win. */
enum RuleKind: string
{
    case Include = 'INCLUDE';
    case Exclude = 'EXCLUDE';
}
