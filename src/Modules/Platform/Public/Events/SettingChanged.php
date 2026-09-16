<?php

declare(strict_types=1);

namespace Modules\Platform\Public\Events;

use Carbon\CarbonImmutable;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;

final readonly class SettingChanged implements ShouldDispatchAfterCommit
{
    /**
     * @param  string|null  $storeId  null for a global setting
     */
    public function __construct(
        public string $eventId,
        public string $key,
        public ?string $storeId,
        public CarbonImmutable $occurredAt,
    ) {}
}
