<?php

declare(strict_types=1);

namespace Modules\Content\Presentation\Http\Resource;

use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/** W6 · Pop-Ups (content.md §4.4): the store's pop-ups, newest first, 50 at a time. */
#[TypeScript]
final class PopupsPageData extends Data
{
    /**
     * @param  list<ContentStoreData>  $stores
     * @param  list<PopupRowData>  $popups
     */
    public function __construct(
        public array $stores,
        public string $storeCode,
        public string $storeTimezone,
        public array $popups,
        public ?string $next,
    ) {}
}
