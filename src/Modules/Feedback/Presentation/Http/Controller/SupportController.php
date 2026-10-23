<?php

declare(strict_types=1);

namespace Modules\Feedback\Presentation\Http\Controller;

use App\Http\FormErrors;
use App\Http\Page;
use Closure;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Response;
use Modules\Feedback\Application\Command\AnswerQuestion\AnswerQuestion;
use Modules\Feedback\Application\Command\AnswerQuestion\AnswerQuestionHandler;
use Modules\Feedback\Application\Command\ApproveReview\ApproveReview;
use Modules\Feedback\Application\Command\ApproveReview\ApproveReviewHandler;
use Modules\Feedback\Application\Command\CorrectAnswer\CorrectAnswer;
use Modules\Feedback\Application\Command\CorrectAnswer\CorrectAnswerHandler;
use Modules\Feedback\Application\Command\RejectQuestion\RejectQuestion;
use Modules\Feedback\Application\Command\RejectQuestion\RejectQuestionHandler;
use Modules\Feedback\Application\Command\RejectReview\RejectReview;
use Modules\Feedback\Application\Command\RejectReview\RejectReviewHandler;
use Modules\Feedback\Application\Command\UnpublishQuestion\UnpublishQuestion;
use Modules\Feedback\Application\Command\UnpublishQuestion\UnpublishQuestionHandler;
use Modules\Feedback\Application\Command\UnpublishReview\UnpublishReview;
use Modules\Feedback\Application\Command\UnpublishReview\UnpublishReviewHandler;
use Modules\Feedback\Application\Query\ListFavourites\ListFavouritesHandler;
use Modules\Feedback\Application\Query\ListQuestions\ListQuestionsHandler;
use Modules\Feedback\Application\Query\ListReviews\ListReviewsHandler;
use Modules\Feedback\Domain\Exception\InvalidFeedbackAttribute;
use Modules\Feedback\Presentation\Http\Resource\SupportPages;
use Shared\Domain\Error\DomainError;

/*
| Feedback's Support screens (feedback.md §4.1 F1–F3): the store's reviews and questions by status, and
| its favourited products - and every decision behind their buttons.
|
| Who may open or change any of these is Feedback's answer, never the routing's: each read and each
| decision asks for its own job in the item's store (§3).
*/
final readonly class SupportController
{
    /** @var list<string> */
    private const array WORDS = ['feedback::admin_support', 'feedback::errors', 'admin'];

    /** The dialogs' own boxes, where a refusal naming one is said. */
    private const array FIELDS = ['reason', 'answer'];

    public function __construct(
        private Page $page,
    ) {}

    /** F1. */
    public function reviews(Request $request, SupportPages $pages, ListReviewsHandler $list): Response
    {
        return $this->page->render('Feedback/Admin/Reviews', $pages->reviews(self::query($request, 'store'), self::query($request, 'status'), self::query($request, 'after'), $list)->toArray(), self::WORDS);
    }

    /** F2. */
    public function questions(Request $request, SupportPages $pages, ListQuestionsHandler $list): Response
    {
        return $this->page->render('Feedback/Admin/Questions', $pages->questions(self::query($request, 'store'), self::query($request, 'status'), self::query($request, 'after'), $list)->toArray(), self::WORDS);
    }

    /** F3. */
    public function favourites(Request $request, SupportPages $pages, ListFavouritesHandler $list): Response
    {
        return $this->page->render('Feedback/Admin/Favourites', $pages->favourites(self::query($request, 'store'), self::query($request, 'after'), $list)->toArray(), self::WORDS);
    }

    public function approve(Request $request, string $review, ApproveReviewHandler $handler): RedirectResponse
    {
        return self::act($request, fn () => $handler->handle(new ApproveReview($review)), 'approved');
    }

    public function reject(Request $request, string $review, RejectReviewHandler $handler): RedirectResponse
    {
        return self::act($request, fn () => $handler->handle(new RejectReview($review, self::text($request, 'reason'))), 'rejected');
    }

    public function unpublish(Request $request, string $review, UnpublishReviewHandler $handler): RedirectResponse
    {
        return self::act($request, fn () => $handler->handle(new UnpublishReview($review, self::text($request, 'reason'))), 'unpublished');
    }

    public function answer(Request $request, string $question, AnswerQuestionHandler $handler): RedirectResponse
    {
        return self::act($request, fn () => $handler->handle(new AnswerQuestion($question, self::text($request, 'answer') ?? '')), 'answered');
    }

    public function correct(Request $request, string $question, CorrectAnswerHandler $handler): RedirectResponse
    {
        return self::act($request, fn () => $handler->handle(new CorrectAnswer($question, self::text($request, 'answer') ?? '')), 'corrected');
    }

    public function rejectQuestion(Request $request, string $question, RejectQuestionHandler $handler): RedirectResponse
    {
        return self::act($request, fn () => $handler->handle(new RejectQuestion($question, self::text($request, 'reason'))), 'question_rejected');
    }

    public function unpublishQuestion(Request $request, string $question, UnpublishQuestionHandler $handler): RedirectResponse
    {
        return self::act($request, fn () => $handler->handle(new UnpublishQuestion($question, self::text($request, 'reason'))), 'question_unpublished');
    }

    /**
     * Does the work and answers with its toast, or with the refusal - beside its own box when it names one.
     *
     * @param  Closure(): mixed  $work
     */
    private static function act(Request $request, Closure $work, string $done): RedirectResponse
    {
        try {
            $work();
        } catch (InvalidFeedbackAttribute $error) {
            if (in_array($error->attribute, self::FIELDS, true) && ! $request->expectsJson()) {
                return back()->withInput()->withErrors([$error->attribute => FormErrors::message($error)]);
            }

            return FormErrors::back($request, $error);
        } catch (DomainError $error) {
            return FormErrors::back($request, $error);
        }

        return back()->with('status', __("feedback::admin_support.toast.{$done}"));
    }

    /** A box's text, trimmed; none when empty. */
    private static function text(Request $request, string $key): ?string
    {
        $value = $request->input($key);

        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }

    private static function query(Request $request, string $key): ?string
    {
        $value = $request->query($key);

        return is_string($value) && trim($value) !== '' && strlen($value) <= 100 ? trim($value) : null;
    }
}
