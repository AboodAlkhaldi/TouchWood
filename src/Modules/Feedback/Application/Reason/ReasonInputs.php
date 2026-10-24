<?php

declare(strict_types=1);

namespace Modules\Feedback\Application\Reason;

use Modules\Feedback\Domain\Exception\InvalidFeedbackAttribute;
use Modules\Feedback\Domain\Model\FeedbackText;
use Shared\Infrastructure\Persistence\Ulids;

/** What a ready reason is made of (feedback.md §2.4 B4): its words in both languages, 1 to 500 each. */
final class ReasonInputs
{
    /**
     * @return array{string, string} the Arabic and the English
     *
     * @throws InvalidFeedbackAttribute
     */
    public static function words(string $textAr, string $textEn): array
    {
        return [FeedbackText::of($textAr, 'text_ar', 500), FeedbackText::of($textEn, 'text_en', 500)];
    }

    /** @throws InvalidFeedbackAttribute */
    public static function id(string $id): string
    {
        $id = strtolower(trim($id));

        return Ulids::valid($id) ? $id : throw new InvalidFeedbackAttribute('reason', 'a ready reason');
    }
}
