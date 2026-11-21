<?php

declare(strict_types=1);

namespace Modules\Feedback\Presentation\Home;

use Modules\Feedback\Application\FeedbackPermissions;
use Modules\Feedback\Application\Query\FeedbackQueries;
use Modules\Platform\Public\Contracts\HomeCard;
use Modules\Platform\Public\Contracts\HomeStores;
use Modules\Platform\Public\Dto\HomeCardData;
use Modules\Platform\Public\Dto\HomeMetric;
use Modules\Platform\Public\Dto\HomeMetricLine;
use Modules\Platform\Public\Dto\HomeScope;
use Modules\Platform\Public\Dto\HomeValue;
use Modules\Platform\Public\Enums\HomeSize;

/**
 * Home's **Average rating** (dashboard.md §2 F, D3): the store's approved reviews' average out of 5 and their count -
 * of all time, so with **no change** against the period before. "—" with none. For whoever moderates reviews
 * (`feedback.review.moderate`, §5). All Stores: a line per store.
 */
final readonly class AverageRatingCard implements HomeCard
{
    public function __construct(
        private HomeStores $stores,
        private FeedbackQueries $queries,
    ) {}

    public function data(HomeScope $scope): ?HomeCardData
    {
        $stores = $this->stores->for($scope, FeedbackPermissions::REVIEW_MODERATE);

        if ($stores === null || $stores === []) {
            return null;
        }

        $locale = app()->getLocale() === 'en' ? 'en' : 'ar';
        $lines = [];

        foreach ($stores as $store) {
            $rating = $this->queries->averageRating($store->storeId());
            $lines[] = new HomeMetricLine(
                count($stores) > 1 ? $store->name->in($locale) : null,
                $rating['count'] === 0 ? null : HomeValue::rating($rating['sum'] / $rating['count']),
                note: (string) trans_choice('feedback::home.rating.reviews', $rating['count'], ['count' => $rating['count']]),
            );
        }

        $href = route('feedback.admin.reviews', count($stores) > 1 ? [] : ['store' => strtolower($stores[0]->code)], absolute: false);

        return new HomeCardData([], size: HomeSize::Third, metrics: [new HomeMetric((string) __('feedback::home.rating.title'), $lines, href: $href)]);
    }
}
