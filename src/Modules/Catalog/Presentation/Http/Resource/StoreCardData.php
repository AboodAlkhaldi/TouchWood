<?php

declare(strict_types=1);

namespace Modules\Catalog\Presentation\Http\Resource;

use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * One store's card on a product's Stores tab (catalog.md §4.4 S9 "Stores", P6): the store, whether it
 * chose the product and what it does with it there, and which of its jobs the reader holds in it -
 * each control drawn only for them, the state shown to everyone.
 */
#[TypeScript]
final class StoreCardData extends Data
{
    /**
     * @param  bool  $active  whether the store is on (a Super Admin also sees the ones that are off)
     * @param  bool  $chosen  whether the store ever chose the product: until then only Switch On is offered
     * @param  list<string>  $labelIds  the labels it carries there, in the labels' order
     * @param  list<StoreVariantData>  $variants  the variants the store has a row for
     */
    public function __construct(
        public string $storeId,
        public string $code,
        public string $name,
        public bool $active,
        public bool $chosen,
        public bool $notAvailable,
        public int $retailMinimum,
        public ?int $retailMaximum,
        public ?int $wholesaleMinimum,
        public ?int $wholesaleMaximum,
        public array $labelIds,
        public array $variants,
        public bool $mayChoose,
        public bool $maySell,
        public bool $mayUnavailable,
        public bool $mayLabel,
    ) {}
}
