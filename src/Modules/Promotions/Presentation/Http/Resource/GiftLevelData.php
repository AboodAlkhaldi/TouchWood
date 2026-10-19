<?php

declare(strict_types=1);

namespace Modules\Promotions\Presentation\Http\Resource;

use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * A gift level as M4 shows it (promotions.md §4.1): its amount shown and as its box holds it
 * (`amountText`, "500.00"); the gift - the product's name in the page's language and the size's code,
 * null once Catalog no longer has it, `archived` then too; who it is for; its dates; and `state`:
 * GIVING (on, within its dates), NOT_STARTED, ENDED or OFF; `photo` the gift's thumbnail, when it has one.
 */
#[TypeScript]
final class GiftLevelData extends Data
{
    public function __construct(
        public string $id,
        public MoneyData $amount,
        public string $amountText,
        public string $variantId,
        public ?string $variantCode,
        public ?string $productName,
        public bool $archived,
        public bool $forPublic,
        public bool $forCompany,
        public ?string $startsAt,
        public ?string $endsAt,
        public bool $active,
        public string $state,
        public ?string $photo,
    ) {}
}
