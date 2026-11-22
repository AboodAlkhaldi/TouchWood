<?php

declare(strict_types=1);

namespace Modules\Catalog\Presentation\Http\Resource;

use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * Searches that found nothing, grouped (catalog.md §1.11, §4.4 S7): the words, the store by name, the
 * language, how many times, and when last. No person.
 */
#[TypeScript]
final class NoResultSearchData extends Data
{
    public function __construct(
        public string $query,
        public string $storeName,
        /** The store's code: two stores may share a name in one language. */
        public string $storeCode,
        public string $locale,
        public int $times,
        public string $lastSearchedAt,
    ) {}
}
