<?php

declare(strict_types=1);

namespace Modules\Ops\Presentation\Http\Controller;

use App\Http\Page;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Response;
use Modules\Ops\Application\Command\SendStaffMessage\SendStaffMessage;
use Modules\Ops\Application\Command\SendStaffMessage\SendStaffMessageHandler;
use Modules\Ops\Application\OpsPermissions;
use Modules\Ops\Application\Query\FindCustomersToMessage\FindCustomersToMessage;
use Modules\Ops\Application\Query\FindCustomersToMessage\FindCustomersToMessageHandler;
use Modules\Ops\Application\Query\ListSegments\ListSegments;
use Modules\Ops\Application\Query\ListSegments\ListSegmentsHandler;
use Modules\Ops\Application\Query\ListStaffMessages\ListStaffMessages;
use Modules\Ops\Application\Query\ListStaffMessages\ListStaffMessagesHandler;
use Modules\Ops\Presentation\Http\Resource\MessageFormPageData;
use Modules\Ops\Presentation\Http\Resource\MessagesListPageData;
use Modules\Ops\Presentation\Http\Resource\StoreChoiceData;
use Modules\Ops\Presentation\Http\Resource\WrittenPages;
use Modules\Platform\Public\Contracts\StoreChoices;
use Shared\Application\Unauthorized;
use Shared\Domain\Error\DomainError;

/*
| The message composer in the panel (ops.md §1.8, §4, §4.15 O3, O4): find the customer, then send - to them, or with
| no customer to a segment or every customer of the store - and the messages sent. A plain visit gets the page and a
| screen's own read the JSON; a message sent by the page goes back with its words (`Answers`), any other gets JSON.
|
| Who may do it is Ops' answer: `ops.message.send_one` for one customer, `ops.message.send_many` for
| a segment or the store, in the store the screen shows.
*/
final readonly class StaffMessagesController
{
    /** @var list<string> */
    private const array WORDS = ['ops::admin_written', 'ops::errors', 'admin'];

    public function __construct(
        private WrittenPages $pages,
        private Page $page,
    ) {}

    public function customers(Request $request, StoreChoices $choices, FindCustomersToMessageHandler $find): JsonResponse
    {
        $store = $choices->chosen(Inputs::text($request, 'store'), OpsPermissions::MESSAGE_SEND_ONE) ?? throw new Unauthorized(OpsPermissions::MESSAGE_SEND_ONE);

        return new JsonResponse(['customers' => array_map(
            static fn ($customer): array => $customer->toArray(),
            $this->pages->customers($find->handle(new FindCustomersToMessage($store->id, Inputs::text($request, 'search') ?? ''))),
        )]);
    }

    public function index(Request $request, StoreChoices $choices, ListStaffMessagesHandler $list): Response|JsonResponse
    {
        $store = $choices->chosen(Inputs::text($request, 'store'), OpsPermissions::MESSAGE_SEND_ONE, OpsPermissions::MESSAGE_SEND_MANY) ?? throw new Unauthorized(OpsPermissions::MESSAGE_SEND_ONE);
        $page = $this->pages->staffMessages($list->handle(new ListStaffMessages($store->id, Inputs::text($request, 'after'))));

        if ($request->expectsJson()) {
            return new JsonResponse($page->toArray());
        }

        return $this->page->render('Ops/Admin/Messages/Index', (new MessagesListPageData(
            $page->messages,
            $page->next,
            StoreChoiceData::listOf($choices->forJobs(OpsPermissions::MESSAGE_SEND_ONE, OpsPermissions::MESSAGE_SEND_MANY)),
            $store->code,
        ))->toArray(), self::WORDS);
    }

    /** O4: New Message, its own page. */
    public function create(Request $request, StoreChoices $choices): Response
    {
        $store = $choices->chosen(Inputs::text($request, 'store'), OpsPermissions::MESSAGE_SEND_ONE, OpsPermissions::MESSAGE_SEND_MANY) ?? throw new Unauthorized(OpsPermissions::MESSAGE_SEND_ONE);

        return $this->page->render('Ops/Admin/Messages/New', (new MessageFormPageData(
            StoreChoiceData::listOf($choices->forJobs(OpsPermissions::MESSAGE_SEND_ONE)),
            StoreChoiceData::listOf($choices->forJobs(OpsPermissions::MESSAGE_SEND_MANY)),
            $store->code,
        ))->toArray(), self::WORDS);
    }

    /** The segments the composer may pick from (§4.3): a store's, under `ops.message.send_many` there. */
    public function segments(Request $request, StoreChoices $choices, ListSegmentsHandler $list): JsonResponse
    {
        $store = $choices->chosen(Inputs::text($request, 'store'), OpsPermissions::MESSAGE_SEND_MANY) ?? throw new Unauthorized(OpsPermissions::MESSAGE_SEND_MANY);

        return new JsonResponse($this->pages->segments($list->handle(new ListSegments($store->id, OpsPermissions::MESSAGE_SEND_MANY, Inputs::text($request, 'after'))))->toArray());
    }

    public function send(Request $request, StoreChoices $choices, SendStaffMessageHandler $send): JsonResponse|RedirectResponse
    {
        if (! Answers::fromPage($request)) {
            return new JsonResponse(['id' => self::sent($request, $choices, $send)], 201);
        }

        try {
            self::sent($request, $choices, $send);
        } catch (DomainError $error) {
            return WrittenRefusals::back($request, $error);
        }

        return redirect()->route('ops.admin.messages', ['store' => Inputs::text($request, 'store')])->with('status', __('ops::admin_written.messages.toast'));
    }

    private static function sent(Request $request, StoreChoices $choices, SendStaffMessageHandler $send): string
    {
        $customer = Inputs::text($request, 'customer');
        $audience = Inputs::text($request, 'audience') ?? '';
        $permission = $audience === 'ONE' ? OpsPermissions::MESSAGE_SEND_ONE : OpsPermissions::MESSAGE_SEND_MANY;
        $store = $choices->chosen(Inputs::text($request, 'store'), $permission) ?? throw new Unauthorized($permission);

        return $send->handle(new SendStaffMessage(
            $store->id,
            $audience,
            $customer,
            Inputs::string($request, 'title_ar'),
            Inputs::string($request, 'title_en'),
            Inputs::string($request, 'body_ar'),
            Inputs::string($request, 'body_en'),
            Inputs::flag($request, 'by_bell'),
            Inputs::flag($request, 'by_email'),
            Inputs::text($request, 'segment') ?? Inputs::text($request, 'segment_id'),
        ));
    }
}
