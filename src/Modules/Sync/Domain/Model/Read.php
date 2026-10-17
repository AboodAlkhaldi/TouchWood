<?php

declare(strict_types=1);

namespace Modules\Sync\Domain\Model;

use DateTimeImmutable;
use Modules\Sync\Domain\ValueObject\ReadKind;

/**
 * One read of a store, as its job runs it (sync.md §1.3, §5 `sync.reads`): what kind, the codes a
 * notified or one-code read asks for, and when it started - what the next hourly read asks from.
 */
final readonly class Read
{
    /**
     * @param  list<string>|null  $codes
     */
    public function __construct(
        public string $id,
        public string $storeId,
        public ReadKind $kind,
        public ?array $codes,
        public DateTimeImmutable $startedAt,
    ) {}

    /** A read of everything the provider has: the night's, Sync now's, a store just wired. */
    public function isFull(): bool
    {
        return $this->codes === null && $this->kind !== ReadKind::Hourly;
    }

    /**
     * Whether it covers every change up to its start - the hour's and every full read - so the next
     * hourly read may ask only for what changed after it (§1.3). A notice's or one code's does not.
     */
    public function coversChanges(): bool
    {
        return $this->codes === null;
    }
}
