<?php

declare(strict_types=1);

namespace Modules\Feedback\Presentation\Http\Resource;

use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/** A question on the customer's My Questions (feedback.md §4.1 F8): its store, its state, and the answer once given. */
#[TypeScript]
final class MyQuestionData extends Data
{
    public function __construct(
        public string $id,
        public ProductCardData $product,
        public string $storeName,
        public string $body,
        public string $locale,
        public string $status,
        public ?string $answer,
        public ?string $answeredAt,
        public ?string $reason,
        public string $askedAt,
    ) {}
}
