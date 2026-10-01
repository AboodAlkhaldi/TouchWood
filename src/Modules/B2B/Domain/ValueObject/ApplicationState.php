<?php

declare(strict_types=1);

namespace Modules\B2B\Domain\ValueObject;

/**
 * Where an application is (b2b.md §4.2). `DRAFT` is the only state the customer can change; once
 * sent it is the staff's; `APPROVED` and `REJECTED` are terminal — a decided application is never
 * reopened, only followed by another.
 */
enum ApplicationState: string
{
    case Draft = 'DRAFT';
    case Submitted = 'SUBMITTED';
    case Approved = 'APPROVED';
    case Rejected = 'REJECTED';

    /** The one open application an account may have at a time (b2b.md §1.2). */
    public function isOpen(): bool
    {
        return $this === self::Draft || $this === self::Submitted;
    }
}
