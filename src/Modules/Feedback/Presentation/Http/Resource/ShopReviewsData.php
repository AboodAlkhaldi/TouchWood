<?php

declare(strict_types=1);

namespace Modules\Feedback\Presentation\Http\Resource;

use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * Feedback's `feedback.reviews` section of a product's page (feedback.md §1.1, §1.2; catalog.md
 * amendment 26(b)): the rating — `average` to one decimal, null with no review — and its count, ten
 * approved reviews newest first, `next` for "Show more" (asked as `after[feedback.reviews]`), and
 * `canWrite` — signed in and not yet reviewed; the purchase is checked when it is sent.
 */
#[TypeScript]
final class ShopReviewsData extends Data
{
    /**
     * @param  list<ShopReviewData>  $reviews
     */
    public function __construct(
        public ?float $average,
        public int $count,
        public array $reviews,
        public ?string $next,
        public bool $canWrite,
    ) {}
}
