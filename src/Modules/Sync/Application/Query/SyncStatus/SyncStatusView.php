<?php

declare(strict_types=1);

namespace Modules\Sync\Application\Query\SyncStatus;

use Modules\Sync\Application\Report\FailureView;

/**
 * **The status line** (sync.md §4.1 Y1; S8, Q29, Q35): the provider - its code and its name ("Odoo") -
 * and the store's state - `WORKING`, `FAILING` with its reason (Q31), or `NOT_WIRED` with no provider;
 * **Last Read**, the newest read that finished done, of any kind, and **Last Full Read**, the start of the
 * last full read that worked; and the read running (its kind and start) or else the first waiting (its
 * kind and when it was asked). Never the address, the settings or the key: those are the Super Admin's
 * (Y3).
 */
final readonly class SyncStatusView
{
    public const string WORKING = 'WORKING';

    public const string FAILING = 'FAILING';

    public const string NOT_WIRED = 'NOT_WIRED';

    public function __construct(
        public ?string $provider,
        public ?string $providerName,
        public string $state,
        public ?FailureView $failure,
        public ?string $lastReadAt,
        public ?string $lastFullReadAt,
        public ?ReadMomentView $running,
        public ?ReadMomentView $waiting,
    ) {}
}
