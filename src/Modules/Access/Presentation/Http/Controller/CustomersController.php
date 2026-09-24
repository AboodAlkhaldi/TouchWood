<?php

declare(strict_types=1);

namespace Modules\Access\Presentation\Http\Controller;

use App\Http\AdminArea;
use App\Http\FormErrors;
use App\Http\Page;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Response;
use Modules\Access\Application\Command\BlockCustomer\BlockCustomer;
use Modules\Access\Application\Command\BlockCustomer\BlockCustomerHandler;
use Modules\Access\Application\Command\CancelCustomerDeletion\CancelCustomerDeletion;
use Modules\Access\Application\Command\CancelCustomerDeletion\CancelCustomerDeletionHandler;
use Modules\Access\Application\Command\DeleteCustomerOnRequest\DeleteCustomerOnRequest;
use Modules\Access\Application\Command\DeleteCustomerOnRequest\DeleteCustomerOnRequestHandler;
use Modules\Access\Application\Command\UnblockCustomer\UnblockCustomer;
use Modules\Access\Application\Command\UnblockCustomer\UnblockCustomerHandler;
use Modules\Access\Presentation\Http\Request\ReasonRequest;
use Modules\Access\Presentation\Http\Resource\CustomerPages;
use Shared\Domain\Error\DomainError;

/**
 * Customers, seen by staff (frontend.md §3.7, G1 and G2).
 *
 * Who appears and what may be done is Access's answer, asked again by every handler behind these
 * routes: a person without the permission gets the refusal in Access's own words, not a missing
 * page (handoff §19).
 *
 * **A customer is never edited here.** Their name, their language, their addresses are their own
 * (§3.7). What staff may do is block an account, unblock it, start the customer's own fourteen-day
 * deletion at their request, and cancel one - all with a reason, because each is a thing somebody
 * will later ask about, and all of them admin-only (R6).
 */
final readonly class CustomersController
{
    /** @var list<string> */
    private const array WORDS = ['access::customers', 'access::errors', 'admin'];

    public function __construct(
        private Page $page,
        private CustomerPages $pages,
    ) {}

    /** G1. */
    public function index(Request $request): Response
    {
        $page = $request->integer('page', 1);

        return $this->page->render('Access/Admin/Customers/Index', $this->pages->list(
            $this->text($request, 'search'),
            $this->text($request, 'status'),
            $this->text($request, 'type'),
            $page < 1 ? 1 : $page,
        )->toArray(), self::WORDS);
    }

    /** G2. */
    public function show(string $customer): Response
    {
        return $this->page->render('Access/Admin/Customers/Show', $this->pages->view($customer)->toArray(), self::WORDS);
    }

    public function block(ReasonRequest $request, string $customer, BlockCustomerHandler $handler): RedirectResponse
    {
        return $this->act($request, fn () => $handler->handle(new BlockCustomer($customer, $request->text('reason'))), $customer, 'access::customers.blocked');
    }

    public function unblock(ReasonRequest $request, string $customer, UnblockCustomerHandler $handler): RedirectResponse
    {
        return $this->act($request, fn () => $handler->handle(new UnblockCustomer($customer, $request->text('reason'))), $customer, 'access::customers.unblocked');
    }

    /**
     * The customer's own deletion, started for them: the same fourteen days, from the same
     * request, for somebody who asked a person rather than pressing the button themselves
     * (access.md §1.10).
     */
    public function requestDeletion(ReasonRequest $request, string $customer, DeleteCustomerOnRequestHandler $handler): RedirectResponse
    {
        return $this->act($request, fn () => $handler->handle(new DeleteCustomerOnRequest($customer, $request->text('reason'))), $customer, 'access::customers.deletion_started');
    }

    /**
     * And stopping one - which is here because a customer who cannot sign in cannot cancel it
     * themselves, and signing in is the only other way (§3.7).
     */
    public function cancelDeletion(ReasonRequest $request, string $customer, CancelCustomerDeletionHandler $handler): RedirectResponse
    {
        return $this->act($request, fn () => $handler->handle(new CancelCustomerDeletion($customer, $request->text('reason'))), $customer, 'access::customers.deletion_cancelled');
    }

    /**
     * One action, refused in Access's own words on the screen it was pressed on.
     *
     * A refusal belongs to the page the person is looking at, never to a 403 that takes the page
     * away from them: this is an action on a screen they already have open (App\Http\FormErrors).
     *
     * @param  callable(): void  $act
     */
    private function act(Request $request, callable $act, string $customer, string $message): RedirectResponse
    {
        try {
            $act();
        } catch (DomainError $error) {
            return FormErrors::back($request, $error, ['reason']);
        }

        return redirect()
            ->to('/'.AdminArea::PREFIX.'/customers/'.$customer)
            ->with('status', __($message));
    }

    private function text(Request $request, string $key): ?string
    {
        $value = $request->query($key);

        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }
}
