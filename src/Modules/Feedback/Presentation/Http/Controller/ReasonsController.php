<?php

declare(strict_types=1);

namespace Modules\Feedback\Presentation\Http\Controller;

use App\Http\FormErrors;
use App\Http\Page;
use Closure;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Response;
use Modules\Feedback\Application\Command\AddRejectReason\AddRejectReason;
use Modules\Feedback\Application\Command\AddRejectReason\AddRejectReasonHandler;
use Modules\Feedback\Application\Command\ChangeRejectReason\ChangeRejectReason;
use Modules\Feedback\Application\Command\ChangeRejectReason\ChangeRejectReasonHandler;
use Modules\Feedback\Application\Command\OrderRejectReasons\OrderRejectReasons;
use Modules\Feedback\Application\Command\OrderRejectReasons\OrderRejectReasonsHandler;
use Modules\Feedback\Application\Command\RemoveRejectReason\RemoveRejectReason;
use Modules\Feedback\Application\Command\RemoveRejectReason\RemoveRejectReasonHandler;
use Modules\Feedback\Application\Query\FeedbackActions\FeedbackActionsForReader;
use Modules\Feedback\Application\Query\ListRejectReasons\ListRejectReasons;
use Modules\Feedback\Application\Query\ListRejectReasons\ListRejectReasonsHandler;
use Modules\Feedback\Application\Query\ListRejectReasons\RejectReasonRow;
use Modules\Feedback\Domain\Exception\InvalidFeedbackAttribute;
use Modules\Feedback\Presentation\Http\Resource\ReasonData;
use Modules\Feedback\Presentation\Http\Resource\ReasonsPage;
use Shared\Domain\Error\DomainError;

/*
| F9 - Support › Rejection Reasons (feedback.md §4.1, §4.2 B4): the ready reasons, one list for every
| store, edited by an admin holding the settings job in every store; read by every moderator.
*/
final readonly class ReasonsController
{
    /** @var list<string> */
    private const array WORDS = ['feedback::admin_reasons', 'feedback::errors', 'admin'];

    /** The dialog's own boxes, where a refusal naming one is said. */
    private const array FIELDS = ['text_ar', 'text_en'];

    public function __construct(
        private Page $page,
    ) {}

    public function index(ListRejectReasonsHandler $list, FeedbackActionsForReader $actions): Response
    {
        return $this->page->render('Feedback/Admin/Reasons', (new ReasonsPage(
            array_map(static fn (RejectReasonRow $row): ReasonData => new ReasonData($row->id, $row->textAr, $row->textEn), $list->handle(new ListRejectReasons)),
            $actions->mayEditReasons(),
        ))->toArray(), self::WORDS);
    }

    public function store(Request $request, AddRejectReasonHandler $handler): RedirectResponse
    {
        return self::act($request, fn () => $handler->handle(new AddRejectReason(self::text($request, 'text_ar'), self::text($request, 'text_en'))), 'added');
    }

    public function update(Request $request, string $reason, ChangeRejectReasonHandler $handler): RedirectResponse
    {
        return self::act($request, fn () => $handler->handle(new ChangeRejectReason($reason, self::text($request, 'text_ar'), self::text($request, 'text_en'))), 'saved');
    }

    public function destroy(Request $request, string $reason, RemoveRejectReasonHandler $handler): RedirectResponse
    {
        return self::act($request, fn () => $handler->handle(new RemoveRejectReason($reason)), 'removed');
    }

    /** The whole new order, after a drag or Move to Top / Bottom (the owner's rule for every list). */
    public function order(Request $request, OrderRejectReasonsHandler $handler): RedirectResponse
    {
        $sent = $request->input('ids');
        $ids = array_values(array_filter(is_array($sent) ? $sent : [], 'is_string'));

        return self::act($request, fn () => $handler->handle(new OrderRejectReasons($ids)), 'ordered');
    }

    /**
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

        return back()->with('status', __("feedback::admin_reasons.toast.{$done}"));
    }

    private static function text(Request $request, string $key): string
    {
        $value = $request->input($key);

        return is_string($value) ? trim($value) : '';
    }
}
