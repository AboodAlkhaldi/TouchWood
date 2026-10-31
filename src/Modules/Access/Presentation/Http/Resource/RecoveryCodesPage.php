<?php

declare(strict_types=1);

namespace Modules\Access\Presentation\Http\Resource;

use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * The ten recovery codes, shown once after a setup or a renewal (amendment 91): copied or downloaded
 * now, never shown again.
 */
#[TypeScript]
final class RecoveryCodesPage extends Data
{
    /**
     * @param  list<string>  $codes
     */
    public function __construct(
        public array $codes,
        /** Where Continue goes: the panel after a setup, the account's Security tab after a renewal. */
        public string $continueTo,
    ) {}
}
