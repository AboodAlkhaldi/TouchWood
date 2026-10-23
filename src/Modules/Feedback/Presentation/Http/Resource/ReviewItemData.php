<?php

declare(strict_types=1);

namespace Modules\Feedback\Presentation\Http\Resource;

use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * A review in the store's queue (feedback.md §4.1 F1): the author's public name only (§1.1), the size
 * bought, its status and mark, the reason when one was written, and who decided it and when.
 */
#[TypeScript]
final class ReviewItemData extends Data
{
    /**
     * @param  list<ShopVariantValueData>  $variant
     */
    public function __construct(
        public string $id,
        public ProductCardData $product,
        public array $variant,
        public ShopAuthorData $author,
        public int $rating,
        public string $body,
        public string $locale,
        public string $status,
        public string $mark,
        public ?string $reason,
        public string $writtenAt,
        public ?string $decidedBy,
        public ?string $decidedAt,
    ) {}
}
