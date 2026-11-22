<?php

declare(strict_types=1);

namespace Modules\Sales\Presentation\Http\Resource;

use Modules\Platform\Public\Dto\MoneyData;
use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/** A gift the edited order could carry (§1.7): a level it reaches, its product and the amount it starts at. */
#[TypeScript]
final class EditGiftData extends Data
{
    public function __construct(
        public string $levelId,
        public string $name,
        public MoneyData $amount,
    ) {}
}
