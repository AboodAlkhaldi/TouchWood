<?php

declare(strict_types=1);

namespace Modules\Sync\Application\Command\ChangeConnection;

/**
 * A Super Admin changes a wired store's connection (sync.md §1.2): the same form, a key or a secret left
 * empty kept as it is; **`removeSecret` removes the webhook secret** (owner, 2026-11-19): the store then takes
 * no notices, only the hourly and nightly reads.
 */
final readonly class ChangeConnection
{
    /**
     * @param  array<string, string>  $settings  the provider's own fields
     */
    public function __construct(
        public string $storeId,
        public string $provider,
        public string $address,
        #[\SensitiveParameter] public ?string $key = null,
        #[\SensitiveParameter] public ?string $secret = null,
        public array $settings = [],
        public bool $removeSecret = false,
    ) {}
}
