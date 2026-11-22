<?php

declare(strict_types=1);

namespace Modules\Feedback\Presentation\Http\Resource;

use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * An approved review on a product's page (feedback.md §1.1): stars 1–5, the text with its line breaks,
 * its language (a "translate" button shows only when it is not the page's), its mark — VERIFIED_PURCHASE
 * or RETURNED — and the size bought.
 */
#[TypeScript]
final class ShopReviewData extends Data
{
    /**
     * @param  list<ShopVariantValueData>  $variant
     */
    public function __construct(
        public string $reviewId,
        public ShopAuthorData $author,
        public int $rating,
        public string $body,
        public string $locale,
        public string $mark,
        public array $variant,
        public string $writtenAt,
    ) {}
}
