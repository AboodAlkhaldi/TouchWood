<?php

declare(strict_types=1);

namespace Modules\Ops\Presentation\Http\Resource;

use Modules\Platform\Public\Dto\StoreDto;
use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * A store one of Ops' forms may offer (ops.md §4): its id and its code, for a form takes one or the other
 * (§4.1), its name in the page's language, and whether it is on - a Super Admin is offered the off ones too.
 */
#[TypeScript]
final class StoreChoiceData extends Data
{
    public function __construct(
        public string $id,
        public string $code,
        public string $name,
        public bool $isOn,
    ) {}

    /**
     * @param  list<StoreDto>  $stores
     * @return list<self>
     */
    public static function listOf(array $stores): array
    {
        $locale = app()->getLocale();

        return array_map(static fn (StoreDto $store): self => new self($store->id, $store->code, $store->name->in($locale), $store->isActive), $stores);
    }
}
