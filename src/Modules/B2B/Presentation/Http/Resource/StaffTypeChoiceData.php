<?php

declare(strict_types=1);

namespace Modules\B2B\Presentation\Http\Resource;

use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * A listed company type a correction may pick; a deactivated one becomes active again when picked
 * (amendment 8(b)).
 */
#[TypeScript]
final class StaffTypeChoiceData extends Data
{
    public function __construct(
        public string $id,
        public string $nameAr,
        public string $nameEn,
        public bool $active,
    ) {}
}
