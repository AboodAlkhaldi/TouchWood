<?php

declare(strict_types=1);

namespace Modules\Feedback\Presentation\Http\Resource;

use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * F1, F2 and F3 (feedback.md §4.1): one store's reviews, questions or favourited products - only the one
 * the page is for filled - with the tab open (`status`), the next page's cursor (null on the last), and
 * whether the reader may act there (`mayAct`: moderate, answer).
 */
#[TypeScript]
final class SupportListPage extends Data
{
    /**
     * @param  list<ReviewItemData>  $reviews
     * @param  list<QuestionItemData>  $questions
     * @param  list<FavouriteItemData>  $favourites
     * @param  list<StoreOptionData>  $stores
     */
    public function __construct(
        public array $reviews,
        public array $questions,
        public array $favourites,
        public ?string $status,
        public ?string $next,
        public bool $mayAct,
        public string $storeCode,
        public string $storeName,
        public bool $storeIsActive,
        public string $storeTimezone,
        public array $stores,
    ) {}
}
