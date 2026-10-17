<?php

declare(strict_types=1);

namespace Modules\Sync\Domain\ValueObject;

/**
 * What a Sync report entry is about (sync.md §1.6; §9.3 #17): a code we do not have, or the whole store
 * (a failed read), or a size of ours - every other kind.
 */
enum ReportKind: string
{
    case UnknownCode = 'UNKNOWN_CODE';
    case NotInProvider = 'NOT_IN_PROVIDER';
    case ArchivedSkipped = 'ARCHIVED_SKIPPED';
    case OffOrDraftPriced = 'OFF_OR_DRAFT_PRICED';
    case SaleStopped = 'SALE_STOPPED';
    case BadValue = 'BAD_VALUE';
    case ReadFailed = 'READ_FAILED';
    case DiscountSkipped = 'DISCOUNT_SKIPPED';
}
