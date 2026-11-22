<?php

declare(strict_types=1);

namespace Modules\Sales\Presentation\Http\Controller;

use App\Http\FormErrors;
use App\Http\Page;
use Closure;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Response;
use Modules\Sales\Application\Command\AcceptReturn\AcceptReturn;
use Modules\Sales\Application\Command\AcceptReturn\AcceptReturnHandler;
use Modules\Sales\Application\Command\CloseReturn\CloseReturn;
use Modules\Sales\Application\Command\CloseReturn\CloseReturnHandler;
use Modules\Sales\Application\Command\CompleteReturn\CompleteReturn;
use Modules\Sales\Application\Command\CompleteReturn\CompleteReturnHandler;
use Modules\Sales\Application\Command\ReceiveReturn\ReceiveReturn;
use Modules\Sales\Application\Command\ReceiveReturn\ReceiveReturnHandler;
use Modules\Sales\Application\Command\RefuseReturn\RefuseReturn;
use Modules\Sales\Application\Command\RefuseReturn\RefuseReturnHandler;
use Modules\Sales\Application\Query\ListReturns\ListReturnsHandler;
use Modules\Sales\Application\Query\PreviewReturnRefund\PreviewReturnRefundHandler;
use Modules\Sales\Application\Query\ViewReturn\ViewReturnHandler;
use Modules\Sales\Presentation\Http\Resource\ReturnPages;
use Shared\Domain\Error\DomainError;

/*
| P5, P6 - the panel's returns and a return (sales.md §4.1), and every change a return's page makes: accept,
| refuse with a reason, mark received - for each line how many came and how many go back to stock - close, and
| complete. Each sends one of §3's commands, which asks again; a refusal comes back in its own words.
*/
final readonly class ReturnsController
{
    /** @var list<string> */
    private const array WORDS = ['sales::admin_orders', 'sales::admin_returns', 'sales::errors', 'admin'];

    public function __construct(
        private Page $page,
        private ReturnPages $pages,
    ) {}

    public function index(Request $request, ListReturnsHandler $list): Response
    {
        return $this->page->render('Sales/Admin/Returns/Index', $this->pages->list(self::query($request, 'store'), self::query($request, 'tab') ?? ReturnPages::TABS[0], self::query($request, 'after'), $list)->toArray(), self::WORDS);
    }

    public function show(string $return, ViewReturnHandler $view, PreviewReturnRefundHandler $previews): Response
    {
        return $this->page->render('Sales/Admin/Returns/Show', $this->pages->view($return, $view, $previews)->toArray(), self::WORDS);
    }

    public function accept(Request $request, string $return, AcceptReturnHandler $handler): RedirectResponse
    {
        return self::act($request, fn () => $handler->handle(new AcceptReturn($return)), 'accepted');
    }

    public function refuse(Request $request, string $return, RefuseReturnHandler $handler): RedirectResponse
    {
        $reason = $request->input('reason');

        return self::act($request, fn () => $handler->handle(new RefuseReturn($return, is_string($reason) ? $reason : '')), 'refused');
    }

    public function receive(Request $request, string $return, ReceiveReturnHandler $handler): RedirectResponse
    {
        $sent = $request->input('lines');
        $lines = [];

        foreach (is_array($sent) ? $sent : [] as $line) {
            if (is_array($line) && is_string($line['orderLineId'] ?? null) && is_numeric($line['received'] ?? null) && is_numeric($line['restocked'] ?? null)) {
                $lines[] = ['orderLineId' => $line['orderLineId'], 'received' => (int) $line['received'], 'restocked' => (int) $line['restocked']];
            }
        }

        return self::act($request, fn () => $handler->handle(new ReceiveReturn($return, $lines)), 'received');
    }

    public function close(Request $request, string $return, CloseReturnHandler $handler): RedirectResponse
    {
        return self::act($request, fn () => $handler->handle(new CloseReturn($return)), 'closed');
    }

    public function complete(Request $request, string $return, CompleteReturnHandler $handler): RedirectResponse
    {
        return self::act($request, fn () => $handler->handle(new CompleteReturn($return)), 'completed');
    }

    private static function act(Request $request, Closure $work, string $done): RedirectResponse
    {
        try {
            $work();
        } catch (DomainError $error) {
            return FormErrors::back($request, $error);
        }

        return back()->with('status', __("sales::admin_returns.toast.{$done}"));
    }

    private static function query(Request $request, string $key): ?string
    {
        $value = $request->query($key);

        return is_string($value) && $value !== '' ? $value : null;
    }
}
