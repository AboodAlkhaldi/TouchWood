<?php

declare(strict_types=1);

namespace Modules\Payments\Presentation\Http\Controller;

use App\Http\FormErrors;
use App\Http\Page;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Response;
use Modules\Payments\Application\Command\CheckAttempt\CheckAttempt;
use Modules\Payments\Application\Command\CheckAttempt\CheckAttemptHandler;
use Modules\Payments\Application\Query\ListPayments\ListPaymentsHandler;
use Modules\Payments\Application\Query\ViewAttempt\ViewAttemptHandler;
use Modules\Payments\Presentation\Http\Resource\PaymentsPages;
use Shared\Domain\Error\DomainError;

/*
| The Payments list and an attempt (payments.md §4.2, Q19–Q21), for `payments.payment.view`: its handlers check it, in
| each store. Ask the Gateway Now asks MyFatoorah once and says the answer in a sentence. No refund button (Q21).
*/
final readonly class PaymentsController
{
    /** @var list<string> */
    private const array WORDS = ['payments::admin_payments', 'payments::errors', 'admin'];

    public function __construct(
        private Page $page,
        private PaymentsPages $pages,
    ) {}

    public function index(Request $request, ListPaymentsHandler $list): Response
    {
        return $this->page->render('Payments/Admin/Payments', $this->pages->list(
            self::query($request, 'store'), self::query($request, 'state'), self::query($request, 'mode'), self::query($request, 'after'), $list,
        )->toArray(), self::WORDS);
    }

    public function show(string $attempt, ViewAttemptHandler $view): Response
    {
        return $this->page->render('Payments/Admin/Attempt', $this->pages->attempt($attempt, $view)->toArray(), self::WORDS);
    }

    public function check(Request $request, string $attempt, CheckAttemptHandler $handler): RedirectResponse
    {
        try {
            $state = $handler->handle(new CheckAttempt($attempt));
        } catch (DomainError $error) {
            return FormErrors::back($request, $error);
        }

        return back()->with('status', __('payments::admin_payments.asked.'.$state->state->value));
    }

    private static function query(Request $request, string $key): ?string
    {
        $value = $request->query($key);

        return is_string($value) && $value !== '' && strlen($value) <= 64 ? $value : null;
    }
}
