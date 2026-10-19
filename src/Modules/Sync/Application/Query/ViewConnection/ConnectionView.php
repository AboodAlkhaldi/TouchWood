<?php

declare(strict_types=1);

namespace Modules\Sync\Application\Query\ViewConnection;

use Modules\Sync\Application\Report\FailureView;

/**
 * What the Provider page shows (sync.md §1.2, §4.1 Y3): never the key or the secret - only that they are
 * set, and when the connection last changed (any change of it, Q33). Not wired: `provider` null, the form
 * offering the providers we have. Wired: the provider and its settings, the state as the Sync Report says
 * it (`WORKING`, `FAILING` with its reason, Q31), the last read and the last full read (Q29, S7), how many
 * sizes would stop being orderable if it were unwired (§1.8). Either way: whether the provider's
 * connection is built yet (Q38) and the address its notices come to, with how many codes one may name (S7).
 */
final readonly class ConnectionView
{
    public const string NOT_WIRED = 'NOT_WIRED';

    /**
     * @param  list<string>  $providers  the providers a store may be wired to
     * @param  array<string, string>  $settings
     * @param  list<string>  $fields  the fields its provider asks for
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
        public ?FailureView $failure,
        public ?string $lastReadAt,
        public ?string $lastFullReadAt,
        public string $noticeAddress,
        public int $noticeMostCodes,
        public int $stopsWhenUnwired,
    ) {}
}
