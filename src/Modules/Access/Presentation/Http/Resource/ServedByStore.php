<?php

declare(strict_types=1);

namespace Modules\Access\Presentation\Http\Resource;

use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * Another of our stores that ships to a country, so an address there belongs in it (access.md
 * amendment 76(c)): its code for the link, its name in the page's language for the words.
 */
#[TypeScript]
final class ServedByStore extends Data
{
    public function __construct(
        public string $code,
        public string $name,
    ) {}
}
