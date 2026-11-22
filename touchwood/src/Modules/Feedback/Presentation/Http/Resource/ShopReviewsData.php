<?php

declare(strict_types=1);

namespace Modules\Feedback\Presentation\Http\Resource;

use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * Feedback's `feedback.reviews` section of a product's page (feedback.md §1.1, §1.2; catalog.md
 * amendment 26(b)): the rating — `average` to one decimal, null with no review — and its count, ten
 * approved reviews newest first, `next` for "Show more" (asked as `after[feedback.reviews]`), and
 * `canWrite` — signed in, not yet reviewed, and the product delivered to them (Sales's verified purchase, as
 * writing one asks; owner's Q14). `stars`: each star's
 * approved reviews, 5 to 1 (§2.4 B1); `star`: the one star the reviews are narrowed to (asked as
 * `filter[feedback.reviews]`), `next` going on within it. `reviewed`: the signed-in customer has written one -
 * the page says "You reviewed this product" in place of the button (§4.1 F6, Q15).
 */
#[TypeScript]
final class ShopReviewsData extends Data
{
    /**
     * @param  list<ShopReviewData>  $reviews
     * @param  list<ShopStarData>  $stars
     */
    public function __construct(
        public ?float $average,
        public int $count,
        public array $reviews,
        public ?string $next,
        public bool $canWrite,
        public array $stars,
        public ?int $star,
        public bool $reviewed = false,
    ) {}
}
