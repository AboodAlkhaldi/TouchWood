<?php

declare(strict_types=1);

namespace Modules\Feedback\Presentation\Http\Resource;

use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * Who wrote a review or asked a question (feedback.md §1.1): "{firstName} {lastInitial}." — or, when
 * `deleted`, "Deleted customer" in the page's language.
 */
#[TypeScript]
final class ShopAuthorData extends Data
{
    public function __construct(
        public string $firstName,
        public string $lastInitial,
        public bool $deleted,
    ) {}
}
