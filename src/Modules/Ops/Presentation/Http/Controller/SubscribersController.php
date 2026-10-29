<?php

declare(strict_types=1);

namespace Modules\Ops\Presentation\Http\Controller;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Modules\Ops\Application\Command\RemoveSubscriber\RemoveSubscriber;
use Modules\Ops\Application\Command\RemoveSubscriber\RemoveSubscriberHandler;
use Modules\Ops\Application\OpsPermissions;
use Modules\Ops\Application\Query\ListSubscribers\ListSubscribers;
use Modules\Ops\Application\Query\ListSubscribers\ListSubscribersHandler;
use Modules\Ops\Presentation\Http\Resource\WrittenPages;
use Modules\Platform\Public\Contracts\StoreChoices;
use Shared\Application\Unauthorized;

/*
| The newsletter's subscribers in the panel (ops.md §1.9, §3, §4): the store's list, and a guest
| removed. JSON, as the bells are. Who may is Ops' answer: `ops.newsletter.manage` in the store.
*/
final readonly class SubscribersController
{
    public function __construct(
        private WrittenPages $pages,
    ) {}

    public function index(Request $request, StoreChoices $choices, ListSubscribersHandler $list): JsonResponse
    {
        $store = $choices->chosen(Inputs::text($request, 'store'), OpsPermissions::NEWSLETTER_MANAGE) ?? throw new Unauthorized(OpsPermissions::NEWSLETTER_MANAGE);

        return new JsonResponse($this->pages->subscribers($list->handle(new ListSubscribers($store->id, Inputs::text($request, 'after'))))->toArray());
    }

    public function remove(string $subscriber, RemoveSubscriberHandler $remove): Response
    {
        $remove->handle(new RemoveSubscriber($subscriber));

        return response()->noContent();
    }
}
