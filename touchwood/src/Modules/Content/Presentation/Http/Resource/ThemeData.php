<?php

declare(strict_types=1);

namespace Modules\Content\Presentation\Http\Resource;

use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * A theme's fields as W5b edits them (content.md §1.8): names, stores, start and end as typed, the version read, its
 * badge and each store's state, and Translate's unread mark (§1.3 #4).
 */
#[TypeScript]
final class ThemeData extends Data
{
    /**
     * @param  list<string>  $storeIds
     * @param  list<RunStateData>  $states
     */
    public function __construct(
        public string $id,
        public string $nameAr,
        public ?string $nameEn,
        public array $storeIds,
        public string $startsLocal,
        public string $endsLocal,
        public int $version,
        public string $state,
        public array $states,
        public bool $translationUnread,
    ) {}
}
