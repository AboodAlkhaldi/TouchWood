<?php

declare(strict_types=1);

namespace Modules\Sync\Domain\Model;

use DateTimeImmutable;
use Modules\Sync\Domain\ValueObject\ConnectionState;
use Modules\Sync\Domain\ValueObject\ProviderKind;

/**
 * A store's connection to its provider (sync.md §1.2, §5): the store is wired while it exists. The key
 * and the notification secret are plain here, in memory only - kept encrypted with the app's key and
 * never shown, logged or audited (owner, 2026-10-13).
 */
final readonly class Connection
{
    /**
     * @param  array<string, string>  $settings  the adapter's own fields
     */
    public function __construct(
        public string $storeId,
        public ProviderKind $provider,
        public string $address,
        public array $settings,
        #[\SensitiveParameter] public string $key,
        #[\SensitiveParameter] public string $secret,
        public ConnectionState $state,
        public ?DateTimeImmutable $lastReadAt,
        public ?string $lastError,
        public string $createdBy,
        public ?DateTimeImmutable $updatedAt = null,
    ) {}
}
