<?php

declare(strict_types=1);

namespace Modules\Feedback\Presentation\Storefront;

use DateTimeInterface;
use Modules\Catalog\Public\Contracts\ShopProductSection;
use Modules\Catalog\Public\Dto\ShopProductVisitDto;
use Modules\Feedback\Application\Shop\ShopFeedback;
use Modules\Feedback\Presentation\Http\Resource\ShopQuestionData;
use Modules\Feedback\Presentation\Http\Resource\ShopQuestionsData;

/** The product's answered questions (feedback.md §1.3), `feedback.questions`. */
final readonly class QuestionsSection implements ShopProductSection
{
    public function __construct(
        private ShopFeedback $feedback,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function read(ShopProductVisitDto $visit): array
    {
        $read = $this->feedback->of($visit);

        return (new ShopQuestionsData(
            array_map(static fn (array $row): ShopQuestionData => new ShopQuestionData(
                $row['question']->id,
                ReviewsSection::author($row['author']),
                $row['question']->body,
                $row['question']->locale,
                $row['question']->answer,
                $row['store'],
                $row['question']->answeredAt->format(DateTimeInterface::ATOM),
            ), $read->questions),
            $read->nextQuestion,
            $read->signedIn,
        ))->toArray();
    }
}
