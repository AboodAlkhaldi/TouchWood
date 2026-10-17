<?php

declare(strict_types=1);

namespace Modules\Sync\Domain\ValueObject;

/**
 * Why a store is read (sync.md §1.3, §5 `reads_kind`): the provider's notice, the hour, the night, a
 * person's Sync now (or a store just wired), one corrected or retried code.
 */
enum ReadKind: string
{
    case Notified = 'NOTIFIED';
    case Hourly = 'HOURLY';
    case Nightly = 'NIGHTLY';
    case Now = 'NOW';
    case Code = 'CODE';
}
