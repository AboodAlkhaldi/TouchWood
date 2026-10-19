<?php

declare(strict_types=1);

use App\Http\FormErrors;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Lang;
use Modules\Feedback\Domain\Exception\AlreadyReviewed;
use Modules\Feedback\Domain\Exception\FeedbackError;
use Modules\Feedback\Domain\Exception\InvalidFeedbackAttribute;
use Modules\Feedback\Domain\Exception\InvalidModeration;
use Modules\Feedback\Domain\Exception\NotAVerifiedPurchase;
use Modules\Feedback\Domain\Exception\QuestionNotFound;
use Modules\Feedback\Domain\Exception\ReviewNotFound;
use Modules\Feedback\Domain\Exception\TooManyWaitingQuestions;
use Modules\Feedback\Domain\Exception\WishlistFull;
use Shared\Domain\Error\ErrorCategory;

/*
| What a person reads when Feedback refuses something (feedback.md §7): each error in their language,
| through the same FormErrors every screen uses, never the English written for the log — and each
| with the type and the status the spec gives it.
*/

/**
 * @return array<string, array{0: FeedbackError, 1: string, 2: ErrorCategory}>
 */
function feedbackErrors(): array
{
    return [
        'not a verified purchase' => [new NotAVerifiedPurchase, 'feedback.not_a_verified_purchase', ErrorCategory::Forbidden],
        'already reviewed' => [new AlreadyReviewed, 'feedback.already_reviewed', ErrorCategory::Conflict],
        'too many waiting questions' => [new TooManyWaitingQuestions, 'feedback.too_many_waiting_questions', ErrorCategory::Conflict],
        'wishlist full' => [new WishlistFull, 'feedback.wishlist_full', ErrorCategory::Conflict],
        'invalid moderation' => [new InvalidModeration, 'feedback.invalid_moderation', ErrorCategory::Conflict],
        'review not found' => [new ReviewNotFound, 'feedback.review_not_found', ErrorCategory::NotFound],
        'question not found' => [new QuestionNotFound, 'feedback.question_not_found', ErrorCategory::NotFound],
        'invalid attribute' => [new InvalidFeedbackAttribute('rating', 'above 5'), 'feedback.invalid_feedback_attribute', ErrorCategory::Invalid],
    ];
}

/**
 * @return list<class-string<FeedbackError>>
 */
function feedbackErrorClassesInFolder(): array
{
    $classes = [];

    foreach (glob(dirname(__DIR__, 4).'/src/Modules/Feedback/Domain/Exception/*.php') ?: [] as $file) {
        /** @var class-string<FeedbackError> $class */
        $class = 'Modules\\Feedback\\Domain\\Exception\\'.basename($file, '.php');

        if (! (new ReflectionClass($class))->isAbstract()) {
            $classes[] = $class;
        }
    }

    sort($classes);

    return $classes;
}

it('checks every error in the module\'s folder, and the folder holds them all', function () {
    $listed = array_map(static fn (array $row): string => $row[0]::class, array_values(feedbackErrors()));
    sort($listed);

    expect(feedbackErrorClassesInFolder())->toHaveCount(8)
        ->and(feedbackErrorClassesInFolder())->toBe($listed);
});

it('gives each error its own type, its status, and a title and a message in both languages', function (FeedbackError $error, string $type, ErrorCategory $category) {
    expect($error->type())->toBe($type)
        ->and($error->category())->toBe($category);

    $name = substr($type, strlen('feedback.'));

    foreach (['ar', 'en'] as $locale) {
        foreach (['title', 'detail'] as $part) {
            expect(Lang::hasForLocale("feedback::errors.{$name}.{$part}", $locale))->toBeTrue("feedback::errors.{$name}.{$part} has no {$locale} text");
        }
    }
})->with(feedbackErrors());

it('gives no two errors one type', function () {
    $types = array_map(static fn (array $row): string => $row[0]->type(), array_values(feedbackErrors()));

    expect($types)->toBe(array_values(array_unique($types)));
});

it('names a refused value as a word, never as its key', function () {
    App::setLocale('en');
    expect(FormErrors::message(new InvalidFeedbackAttribute('body', 'too long')))->toBe('The text isn\'t valid. Check it and try again.');

    App::setLocale('ar');
    expect(FormErrors::message(new InvalidFeedbackAttribute('rating', 'above 5')))->toBe('قيمة عدد النجوم غير صالحة. تحقّق منها وحاول مجددًا.');
});

it('has a word in both languages for every value a refusal can name', function (string $field) {
    foreach (['ar', 'en'] as $locale) {
        expect(Lang::hasForLocale("feedback::errors.fields.{$field}", $locale))->toBeTrue("no {$locale} word for {$field}");
    }
})->with(['rating', 'body', 'answer', 'reason', 'product', 'variant', 'store', 'customer', 'locale']);

it('never shows the English written for the log', function (FeedbackError $error) {
    App::setLocale('ar');

    expect(FormErrors::message($error))->not->toBe($error->getMessage());
})->with(feedbackErrors());
