<?php

declare(strict_types=1);

namespace Modules\Feedback\Presentation\Http\Resource;

use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/** A review on the customer's My Reviews (feedback.md §4.1 F8): the product in the store it was written in, and its state. */
#[TypeScript]
final class MyReviewData extends Data
{
    /**
     * @param  list<ShopVariantValueData>  $variant
     */
    public function __construct(
        public string $id,
        public ProductCardData $product,
        public string $storeName,
        public array $variant,
        public int $rating,
        public string $body,
        public string $locale,
        public string $status,
        public string $mark,
        public ?string $reason,
        public string $writtenAt,
    ) {}
}
