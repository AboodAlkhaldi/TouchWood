<?php

declare(strict_types=1);

namespace Modules\Access\Presentation\Http\Resource;

use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * One store the reader may change the address form of, named in the panel's language. An off store
 * is listed only to a Super Admin, who prepares it before it opens (access.md amendment 58(a)), and
 * `isActive` lets the page mark it Off.
 */
#[TypeScript]
final class AddressFormatStore extends Data
{
    public function __construct(
        public string $id,
        public string $code,
        public string $name,
        public bool $hasFormat,
        public bool $isActive = true,
    ) {}
}
