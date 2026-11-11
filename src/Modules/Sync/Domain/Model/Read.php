<?php

declare(strict_types=1);

namespace Modules\Sync\Domain\Model;

use DateTimeImmutable;
use Modules\Sync\Domain\ValueObject\ReadKind;

/**
 * One read of a store, as its job runs it (sync.md §1.3, §5 `sync.reads`): what kind, the codes a
 * notified or one-code read asks for - every other asks for every code of ours (§2.4) - and when it
 * started.
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

    /**
     * A full read, kept as the store's last full read: the night's, Sync now's, a store just wired. The
     * hour's asks for everything too (§2.4), but is the hour's check, not the full one (§1.3, Q29).
     */
    public function isFull(): bool
    {
        return $this->codes === null && $this->kind !== ReadKind::Hourly;
    }
}
