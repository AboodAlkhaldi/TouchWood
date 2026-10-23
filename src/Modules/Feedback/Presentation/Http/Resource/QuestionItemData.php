<?php

declare(strict_types=1);

namespace Modules\Feedback\Presentation\Http\Resource;

use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * A question in the store's queue (feedback.md §4.1 F2): the asker's public name only, the answer once
 * given with who answered and when (staff only, owner's Q7), and the reason when one was written.
 */
#[TypeScript]
final class QuestionItemData extends Data
{
    public function __construct(
        public string $id,
        public ProductCardData $product,
        public ShopAuthorData $author,
        public string $body,
        public string $locale,
        public string $status,
        public ?string $answer,
        public ?string $answeredBy,
        public ?string $answeredAt,
        public ?string $reason,
        public string $askedAt,
    ) {}
}
