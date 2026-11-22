<?php

declare(strict_types=1);

namespace Modules\Sync\Application\Command\WireStore;

/**
 * A Super Admin wires a store to its provider (sync.md §1.2).
 */
final readonly class WireStore
{
    /**
     * @param  array<string, string>  $settings  the provider's own fields
     */
    public function __construct(
        public string $storeId,
        public string $provider,
        public string $address,
        #[\SensitiveParameter] public string $key,
        #[\SensitiveParameter] public string $secret,
        public array $settings = [],
    ) {}
}
