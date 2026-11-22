<?php

declare(strict_types=1);

namespace Modules\Ops\Presentation\Http\Controller;

use App\Http\FormErrors;
use App\Http\Page;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Inertia\Response as InertiaResponse;
use Modules\Ops\Application\Command\RemoveSubscriber\RemoveSubscriber;
use Modules\Ops\Application\Command\RemoveSubscriber\RemoveSubscriberHandler;
use Modules\Ops\Application\OpsPermissions;
use Modules\Ops\Application\Query\ListSubscribers\ListSubscribers;
use Modules\Ops\Application\Query\ListSubscribers\ListSubscribersHandler;
use Modules\Ops\Presentation\Http\Resource\StoreChoiceData;
use Modules\Ops\Presentation\Http\Resource\SubscribersListPageData;
use Modules\Ops\Presentation\Http\Resource\WrittenPages;
use Modules\Platform\Public\Contracts\StoreChoices;
use Shared\Application\Unauthorized;
use Shared\Domain\Error\DomainError;

/*
| The newsletter's subscribers in the panel (ops.md §1.9, §3, §4, §4.15 O7): the store's list, and a guest removed. A
| plain visit gets the page and a screen's own read the JSON; a removal sent by the page goes back with its words
| (`Answers`), any other answers as §4.5 says. Who may is Ops' answer: `ops.newsletter.manage` in the store.
*/
final readonly class SubscribersController
{
    /** @var list<string> */
    private const array WORDS = ['ops::admin_subscribers', 'ops::errors', 'admin'];

    public function __construct(
        private WrittenPages $pages,
        private Page $page,
    ) {}

    public function index(Request $request, StoreChoices $choices, ListSubscribersHandler $list): InertiaResponse|JsonResponse
    {
        $store = $choices->chosen(Inputs::text($request, 'store'), OpsPermissions::NEWSLETTER_MANAGE) ?? throw new Unauthorized(OpsPermissions::NEWSLETTER_MANAGE);
        $page = $this->pages->subscribers($list->handle(new ListSubscribers($store->id, Inputs::text($request, 'after'))));

        if ($request->expectsJson()) {
            return new JsonResponse($page->toArray());
        }

        return $this->page->render('Ops/Admin/Subscribers', (new SubscribersListPageData(
            $page->subscribers,
            $page->next,
            StoreChoiceData::listOf($choices->forJobs(OpsPermissions::NEWSLETTER_MANAGE)),
            $store->code,
            $store->timezone,
        ))->toArray(), self::WORDS);
    }

    public function remove(Request $request, string $subscriber, RemoveSubscriberHandler $remove): Response|RedirectResponse
    {
        if (! Answers::fromPage($request)) {
            $remove->handle(new RemoveSubscriber($subscriber));

            return response()->noContent();
        }

        try {
            $remove->handle(new RemoveSubscriber($subscriber));
        } catch (DomainError $error) {
            return FormErrors::back($request, $error);
        }

        return back()->with('status', __('ops::admin_subscribers.remove.toast'));
    }
}
