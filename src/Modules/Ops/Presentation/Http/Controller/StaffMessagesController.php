<?php

declare(strict_types=1);

namespace Modules\Ops\Presentation\Http\Controller;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Ops\Application\Command\SendStaffMessage\SendStaffMessage;
use Modules\Ops\Application\Command\SendStaffMessage\SendStaffMessageHandler;
use Modules\Ops\Application\OpsPermissions;
use Modules\Ops\Application\Query\FindCustomersToMessage\FindCustomersToMessage;
use Modules\Ops\Application\Query\FindCustomersToMessage\FindCustomersToMessageHandler;
use Modules\Ops\Presentation\Http\Resource\WrittenPages;
use Modules\Platform\Public\Contracts\StoreChoices;
use Shared\Application\Unauthorized;

/*
| The message composer in the panel (ops.md §1.8, §4): find the customer, then send - to them, or with
| no customer to every customer of the store. JSON, as the bells are.
|
| Who may do it is Ops' answer: `ops.message.send_one` for one customer, `ops.message.send_many` for
| the store, in the store the screen shows.
*/
final readonly class StaffMessagesController
{
    public function __construct(
        private WrittenPages $pages,
    ) {}

    public function customers(Request $request, StoreChoices $choices, FindCustomersToMessageHandler $find): JsonResponse
    {
        $store = $choices->chosen(Inputs::text($request, 'store'), OpsPermissions::MESSAGE_SEND_ONE) ?? throw new Unauthorized(OpsPermissions::MESSAGE_SEND_ONE);

        return new JsonResponse(['customers' => array_map(
            static fn ($customer): array => $customer->toArray(),
            $this->pages->customers($find->handle(new FindCustomersToMessage($store->id, Inputs::text($request, 'search') ?? ''))),
        )]);
    }

    public function send(Request $request, StoreChoices $choices, SendStaffMessageHandler $send): JsonResponse
    {
        $customer = Inputs::text($request, 'customer');
        $permission = $customer === null ? OpsPermissions::MESSAGE_SEND_MANY : OpsPermissions::MESSAGE_SEND_ONE;
        $store = $choices->chosen(Inputs::text($request, 'store'), $permission) ?? throw new Unauthorized($permission);

        $id = $send->handle(new SendStaffMessage(
            $store->id,
            $customer,
            Inputs::string($request, 'title_ar'),
            Inputs::string($request, 'title_en'),
            Inputs::string($request, 'body_ar'),
            Inputs::string($request, 'body_en'),
            Inputs::flag($request, 'by_bell'),
            Inputs::flag($request, 'by_email'),
        ));

        return new JsonResponse(['id' => $id], 201);
    }
}
