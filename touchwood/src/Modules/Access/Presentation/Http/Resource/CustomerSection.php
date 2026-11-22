<?php

declare(strict_types=1);

namespace Modules\Access\Presentation\Http\Resource;

use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * A section another module adds to G2 (access.md amendment 72(a)): whose it is, and its data as the
 * module read it. The module's own component, `sections/customer/{module}-{key}.tsx`, draws it and
 * knows the data's shape; Access does not.
 */
#[TypeScript]
final class CustomerSection extends Data
{
    /**
     * @param  array<string, mixed>  $data
     */
    public function __construct(
        public string $module,
        public string $key,
        public array $data,
    ) {}
}
