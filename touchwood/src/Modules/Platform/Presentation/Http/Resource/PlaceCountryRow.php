<?php

declare(strict_types=1);

namespace Modules\Platform\Presentation\Http\Resource;

use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * An address country on E8 (platform.md §4.3; owner, 2026-10-29, Q9).
 */
#[TypeScript]
final class PlaceCountryRow extends Data
{
    public function __construct(
        public string $code,
        /** In the page's language; the other language under it. */
        public string $name,
        public string $otherName,
        public bool $retired,
        /** A region in use: else customers type their places there, and no store may ship there. */
        public bool $hasPlaces,
        /** The store whose ship-to list holds it, off ones too; null when none does. */
        public ?PlaceStoreRow $store,
        /** Its regions and cities in use. */
        public int $regions,
        public int $cities,
    ) {}
}
