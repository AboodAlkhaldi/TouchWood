<?php

declare(strict_types=1);

namespace Modules\Content\Presentation\Http\Resource;

use Modules\Platform\Public\Dto\StoreDto;
use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * A store a Content screen offers (content.md §4.2): its id (what the forms send), its code (what the address names),
 * its name in the page's language, whether it is on - a Super Admin is offered the off ones too, marked - and its time
 * zone, which every moment typed or shown is in (§1.1 rule 6).
 */
#[TypeScript]
final class ContentStoreData extends Data
{
    public function __construct(
        public string $id,
        public string $code,
        public string $name,
        public bool $isOn,
        public string $timezone,
    ) {}

    public static function of(StoreDto $store): self
    {
        return new self(strtolower($store->id), $store->code, $store->name->in(app()->getLocale()), $store->isActive, $store->timezone);
    }

    /**
     * @param  list<StoreDto>  $stores
     * @return list<self>
     */
    public static function listOf(array $stores): array
    {
        return array_map(self::of(...), $stores);
    }
}
