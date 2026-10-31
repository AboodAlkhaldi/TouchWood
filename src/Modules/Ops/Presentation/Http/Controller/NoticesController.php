<?php

declare(strict_types=1);

namespace Modules\Ops\Presentation\Http\Controller;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Ops\Application\Command\CancelNotice\CancelNotice;
use Modules\Ops\Application\Command\CancelNotice\CancelNoticeHandler;
use Modules\Ops\Application\Command\ChangeNotice\ChangeNotice;
use Modules\Ops\Application\Command\ChangeNotice\ChangeNoticeHandler;
use Modules\Ops\Application\Command\CreateNotice\CreateNotice;
use Modules\Ops\Application\Command\CreateNotice\CreateNoticeHandler;
use Modules\Ops\Application\OpsPermissions;
use Modules\Ops\Application\Query\ListNotices\ListNotices;
use Modules\Ops\Application\Query\ListNotices\ListNoticesHandler;
use Modules\Ops\Application\Query\ViewNotice\ViewNotice;
use Modules\Ops\Application\Query\ViewNotice\ViewNoticeHandler;
use Modules\Ops\Presentation\Http\Resource\WrittenPages;
use Modules\Platform\Public\Contracts\StoreChoices;
use Shared\Application\Unauthorized;

/*
| Offer notices in the panel (ops.md §1.7, §4): the store's list, one notice, write one, change or
| cancel one that waits. JSON, as the bells are: the screens agent builds the pages on these shapes.
|
| Who may do any of these is Ops' answer, never the routing's: each handler asks for
| `ops.notice.manage` in the store.
*/
final readonly class NoticesController
{
    public function __construct(
        private WrittenPages $pages,
    ) {}

    public function index(Request $request, StoreChoices $choices, ListNoticesHandler $list): JsonResponse
    {
        $store = $choices->chosen(Inputs::text($request, 'store'), OpsPermissions::NOTICE_MANAGE) ?? throw new Unauthorized(OpsPermissions::NOTICE_MANAGE);

        return new JsonResponse($this->pages->notices($list->handle(new ListNotices($store->id, Inputs::text($request, 'after'))))->toArray());
    }

    public function show(string $notice, ViewNoticeHandler $view): JsonResponse
    {
        return new JsonResponse($this->pages->notice($view->handle(new ViewNotice($notice)))->toArray());
    }

    public function store(Request $request, StoreChoices $choices, CreateNoticeHandler $create, ViewNoticeHandler $view): JsonResponse
    {
        $store = $choices->chosen(Inputs::text($request, 'store'), OpsPermissions::NOTICE_MANAGE) ?? throw new Unauthorized(OpsPermissions::NOTICE_MANAGE);
        $id = $create->handle(new CreateNotice(
            $store->id,
            Inputs::string($request, 'title_ar'),
            Inputs::string($request, 'title_en'),
            Inputs::string($request, 'body_ar'),
            Inputs::string($request, 'body_en'),
            Inputs::text($request, 'link'),
            Inputs::flag($request, 'by_bell'),
            Inputs::flag($request, 'by_email'),
            Inputs::text($request, 'send_at'),
            Inputs::text($request, 'segment') ?? Inputs::text($request, 'segment_id'),
        ));

        return new JsonResponse($this->pages->notice($view->handle(new ViewNotice($id)))->toArray(), 201);
    }

    public function update(Request $request, string $notice, ChangeNoticeHandler $change, ViewNoticeHandler $view): JsonResponse
    {
        $change->handle(new ChangeNotice(
            $notice,
            Inputs::string($request, 'title_ar'),
            Inputs::string($request, 'title_en'),
            Inputs::string($request, 'body_ar'),
            Inputs::string($request, 'body_en'),
            Inputs::text($request, 'link'),
            Inputs::flag($request, 'by_bell'),
            Inputs::flag($request, 'by_email'),
            Inputs::text($request, 'send_at'),
            self::segment($request),
        ));

        return new JsonResponse($this->pages->notice($view->handle(new ViewNotice($notice)))->toArray());
    }

    public function cancel(string $notice, CancelNoticeHandler $cancel, ViewNoticeHandler $view): JsonResponse
    {
        $cancel->handle(new CancelNotice($notice));

        return new JsonResponse($this->pages->notice($view->handle(new ViewNotice($notice)))->toArray());
    }

    /**
     * A change's audience (§1.7), as `segment` or `segment_id` (the resource's name): absent keeps the
     * notice's; sent empty, every customer of the store; otherwise that segment.
     */
    private static function segment(Request $request): ?string
    {
        foreach (['segment', 'segment_id'] as $key) {
            if ($request->exists($key)) {
                return Inputs::text($request, $key) ?? '';
            }
        }

        return null;
    }
}
