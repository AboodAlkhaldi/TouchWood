<?php

declare(strict_types=1);

namespace Modules\Sync\Presentation\Http\Resource;

use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * A store's connection as the Provider page shows it (sync.md §4.1 Y3): never the key or the secret.
 */
#[TypeScript]
final class ConnectionData extends Data
{
    /**
     * @param  list<string>  $providers
     * @param  array<string, string>  $settings
     * @param  list<string>  $fields
     */
    public function __construct(
        public string $storeId,
        public array $providers,
        public ?string $provider,
        public ?string $providerName,
        public bool $providerReady,
        public ?string $address,
        public array $settings,
        public array $fields,
        public bool $keySet,
        public ?string $changedAt,
        public string $state,
        public ?FailureData $failure,
        public ?string $lastReadAt,
        public ?string $lastFullReadAt,
        public string $noticeAddress,
        public int $noticeMostCodes,
        public int $stopsWhenUnwired,
    ) {}
}
