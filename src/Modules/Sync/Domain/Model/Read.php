<?php

declare(strict_types=1);

namespace Modules\Sync\Domain\Model;

use Modules\Sync\Domain\ValueObject\ReadKind;

/**
 * One read of a store, as its job runs it (sync.md §1.3, §5 `sync.reads`): what kind, and the codes a
 * notified or one-code read asks for.
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
    ) {}

    /** A read of everything the provider has: the night's, Sync now's, a store just wired. */
    public function isFull(): bool
    {
        return $this->codes === null && $this->kind !== ReadKind::Hourly;
    }
}
