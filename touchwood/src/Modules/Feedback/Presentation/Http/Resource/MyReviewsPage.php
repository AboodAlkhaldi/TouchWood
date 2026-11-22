<?php

declare(strict_types=1);

namespace Modules\Feedback\Presentation\Http\Resource;

use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/** F8 - My Reviews: the customer's reviews in every store, newest first, 20 a page; `next` the next page's cursor. */
#[TypeScript]
final class MyReviewsPage extends Data
{
    /**
     * @param  list<MyReviewData>  $reviews
     */
    public function __construct(
        public array $reviews,
        public ?string $next,
    ) {}
}
