<?php

declare(strict_types=1);

namespace Modules\Sync\Application\Query\ViewConnection;

/**
 * What the Provider page shows (sync.md §1.2): never the key or the secret - only that they are set,
 * and when; how many sizes would stop being orderable if it were unwired (§1.8). Not wired: `provider`
 * null.
 */
final readonly class ConnectionView
{
    /**
     * @param  array<string, string>  $settings
     * @param  list<string>  $fields  the fields its provider asks for
     */
    public function __construct(
        public string $storeId,
        public ?string $provider,
        public ?string $address,
        public array $settings,
        public array $fields,
        public bool $keySet,
        public ?string $setAt,
        public ?string $state,
        public ?string $lastReadAt,
        public ?string $lastError,
        public int $stopsWhenUnwired,
    ) {}
}
