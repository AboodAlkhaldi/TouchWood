<?php

declare(strict_types=1);

namespace Modules\Feedback\Presentation\Http\Resource;

use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * F9 · Support › Rejection Reasons (feedback.md §4.1): the ready reasons, in order; `mayEdit` for an admin
 * holding the settings job in every store - anyone else reads them only (§4.2 B4).
 */
#[TypeScript]
final class ReasonsPage extends Data
{
    /**
     * @param  list<ReasonData>  $reasons
     */
    public function __construct(
        public array $reasons,
        public bool $mayEdit,
    ) {}
}
