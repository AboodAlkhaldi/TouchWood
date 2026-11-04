<?php

declare(strict_types=1);

namespace Modules\Ops\Presentation\Http\Resource;

use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/** Subscribers (ops.md §4.15 O7): the store's page of subscribers and the stores on offer. */
#[TypeScript]
final class SubscribersListPageData extends Data
{
    /**
     * @param  list<SubscriberData>  $subscribers
     * @param  list<StoreChoiceData>  $stores
     */
    public function __construct(
        public array $subscribers,
        public ?string $next,
        public array $stores,
        public string $storeCode,
    ) {}
}
