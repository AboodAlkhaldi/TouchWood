<?php

declare(strict_types=1);

namespace Modules\Feedback\Presentation\Home;

use Modules\Feedback\Application\FeedbackPermissions;
use Modules\Feedback\Application\Query\FeedbackQueries;
use Modules\Feedback\Public\Enums\QuestionStatus;
use Modules\Platform\Public\Contracts\HomeCard;
use Modules\Platform\Public\Contracts\HomeStores;
use Modules\Platform\Public\Dto\HomeCardData;
use Modules\Platform\Public\Dto\HomeFigure;
use Modules\Platform\Public\Dto\HomeRow;
use Modules\Platform\Public\Dto\HomeScope;

/**
 * Needs Attention's **Questions Waiting** (dashboard.md §2 J): the customers' questions not yet answered, now - a
 * line per store with any. For whoever answers questions (`feedback.question.answer`, §5); not shown with none.
 */
final readonly class QuestionsWaitingCard implements HomeCard
{
    public function __construct(
        private HomeStores $stores,
        private FeedbackQueries $queries,
    ) {}

    public function data(HomeScope $scope): ?HomeCardData
    {
        $stores = $this->stores->for($scope, FeedbackPermissions::QUESTION_ANSWER);

        if ($stores === null) {
            return null;
        }

        $locale = app()->getLocale() === 'en' ? 'en' : 'ar';
        $waiting = 0;
        $rows = [];

        foreach ($stores as $store) {
            $count = $this->queries->questionCounts($store->storeId())[QuestionStatus::Pending->value] ?? 0;

            if ($count === 0) {
                continue;
            }

            $waiting += $count;
            $rows[] = new HomeRow(
                $store->name->in($locale),
                (string) trans_choice('feedback::home.questions.count', $count, ['count' => $count]),
                href: route('feedback.admin.questions', ['store' => strtolower($store->code)], absolute: false),
            );
        }

        return $waiting === 0 ? null : new HomeCardData(
            [new HomeFigure('waiting', $waiting, tone: 'amber')],
            count($stores) > 1 ? $rows : [],
            count($stores) > 1 ? 'stores' : null,
            route('feedback.admin.questions', count($stores) > 1 ? [] : ['store' => strtolower($stores[0]->code)], absolute: false),
        );
    }
}
