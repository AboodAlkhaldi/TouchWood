<?php

declare(strict_types=1);

namespace Modules\Ops\Presentation\Http\Resource;

use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/** A page of a store's newsletter list (ops.md §1.9, §4): `next` the cursor for the following page (`?after=`), null on the last. */
#[TypeScript]
final class SubscribersPageData extends Data
{
    /**
     * @param  list<SubscriberData>  $subscribers
     */
    public function __construct(
        public array $subscribers,
        public ?string $next,
    ) {}
}
