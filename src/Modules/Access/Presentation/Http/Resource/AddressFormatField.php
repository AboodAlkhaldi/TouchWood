<?php

declare(strict_types=1);

namespace Modules\Access\Presentation\Http\Resource;

use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * One field of a store's address form, as the editor holds it.
 *
 * Both labels are here, not one: a field is named in both languages because a customer reads the
 * shop in either, and this is the screen where both are written.
 */
#[TypeScript]
final class AddressFormatField extends Data
{
    public function __construct(
        public string $key,
        public string $labelAr,
        public string $labelEn,
        public bool $required,
        public int $maxLength,
    ) {}
}
