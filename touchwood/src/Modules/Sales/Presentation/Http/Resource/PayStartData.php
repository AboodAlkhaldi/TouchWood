<?php

declare(strict_types=1);

namespace Modules\Sales\Presentation\Http\Resource;

use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * What the page follows after Pay (payments.md §1.4 steps 5, 6). `frameUrl` is the bank's page, null when the method
 * needs none. `serverNow` keeps the time left right on a browser whose clock is wrong.
 */
#[TypeScript]
final class PayStartData extends Data
{
    public function __construct(
        public string $tryId,
        public ?string $frameUrl,
        public string $windowEndsAt,
        /** Until when it can still go through, for the words on leaving the bank's page (payments.md §1.7). */
        public string $holdEndsAt,
        public string $serverNow,
    ) {}
}
