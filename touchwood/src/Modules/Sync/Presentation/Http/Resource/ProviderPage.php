<?php

declare(strict_types=1);

namespace Modules\Sync\Presentation\Http\Resource;

use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * **The Provider page** (sync.md §4.1 Y3), a Super Admin's: the frame - every store - and the store's
 * connection.
 */
#[TypeScript]
final class ProviderPage extends Data
{
    public function __construct(
        public SyncFrameData $storeFrame,
        public string $storeTimezone,
        public ConnectionData $connection,
    ) {}
}
