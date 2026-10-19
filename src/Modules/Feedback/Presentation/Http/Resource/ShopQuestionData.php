<?php

declare(strict_types=1);

namespace Modules\Feedback\Presentation\Http\Resource;

use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * An answered question on a product's page (feedback.md §1.3): the answer signed "{store}'s team" —
 * the store that answered, wherever it is read — never a staff member's name.
 */
#[TypeScript]
final class ShopQuestionData extends Data
{
    public function __construct(
        public string $questionId,
        public ShopAuthorData $author,
        public string $body,
        public string $locale,
        public string $answer,
        public string $store,
        public string $answeredAt,
    ) {}
}
