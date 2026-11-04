<?php

declare(strict_types=1);

namespace Modules\Ops\Presentation\Http\Resource;

use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/** Customer Messages (ops.md §4.15 O3): the store's page of messages, the stores on offer, and whether New Message is theirs. */
#[TypeScript]
final class MessagesListPageData extends Data
{
    /**
     * @param  list<StaffMessageData>  $messages
     * @param  list<StoreChoiceData>  $stores
     */
    public function __construct(
        public array $messages,
        public ?string $next,
        public array $stores,
        public string $storeCode,
    ) {}
}
