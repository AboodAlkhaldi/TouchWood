<?php

declare(strict_types=1);

namespace Modules\Ops\Presentation\Http\Controller;

use App\Http\Page;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\ViewErrorBag;
use Inertia\Response;
use Modules\Ops\Application\Command\CancelNotice\CancelNotice;
use Modules\Ops\Application\Command\CancelNotice\CancelNoticeHandler;
use Modules\Ops\Application\Command\ChangeNotice\ChangeNotice;
use Modules\Ops\Application\Command\ChangeNotice\ChangeNoticeHandler;
use Modules\Ops\Application\Command\CreateNotice\CreateNotice;
use Modules\Ops\Application\Command\CreateNotice\CreateNoticeHandler;
use Modules\Ops\Application\OpsPermissions;
use Modules\Ops\Application\Query\ListNotices\ListNotices;
use Modules\Ops\Application\Query\ListNotices\ListNoticesHandler;
use Modules\Ops\Application\Query\ListSegments\ListSegments;
use Modules\Ops\Application\Query\ListSegments\ListSegmentsHandler;
use Modules\Ops\Application\Query\ViewNotice\ViewNotice;
use Modules\Ops\Application\Query\ViewNotice\ViewNoticeHandler;
use Modules\Ops\Presentation\Http\Resource\NoticeData;
use Modules\Ops\Presentation\Http\Resource\NoticeFormPageData;
use Modules\Ops\Presentation\Http\Resource\NoticesListPageData;
use Modules\Ops\Presentation\Http\Resource\NoticeStoreData;
use Modules\Ops\Presentation\Http\Resource\StoreChoiceData;
use Modules\Ops\Presentation\Http\Resource\WrittenPages;
use Modules\Platform\Public\Contracts\StoreChoices;
use Modules\Platform\Public\Dto\StoreDto;
use Shared\Application\Unauthorized;
use Shared\Domain\Error\DomainError;

/*
| Offer notices in the panel (ops.md §1.7, §4, §4.15 O5, O6): the store's list, one notice, write one, change or
| cancel one that waits. A plain visit gets the page and a screen's own read the JSON; a change sent by the page goes
| back with its words (`Answers`), any other gets the notice as JSON.
|
| Who may do any of these is Ops' answer, never the routing's: each handler asks for
| `ops.notice.manage` in the store.
*/
final readonly class NoticesController
{
    /** @var list<string> */
    private const array WORDS = ['ops::admin_written', 'ops::errors', 'admin'];

    public function __construct(
        private WrittenPages $pages,
        private Page $page,
    ) {}

    public function index(Request $request, StoreChoices $choices, ListNoticesHandler $list): Response|JsonResponse
    {
        $store = $choices->chosen(Inputs::text($request, 'store'), OpsPermissions::NOTICE_MANAGE) ?? throw new Unauthorized(OpsPermissions::NOTICE_MANAGE);
        $page = $this->pages->notices($list->handle(new ListNotices($store->id, Inputs::text($request, 'after'))));

        if ($request->expectsJson()) {
            return new JsonResponse($page->toArray());
        }

        return $this->page->render('Ops/Admin/Notices/Index', (new NoticesListPageData(
            $page->notices,
            $page->next,
            StoreChoiceData::listOf($choices->forJobs(OpsPermissions::NOTICE_MANAGE)),
            $store->code,
            $store->timezone,
        ))->toArray(), self::WORDS);
    }

    /** O6: New Notice. */
    public function create(Request $request, StoreChoices $choices): Response
    {
        $store = $choices->chosen(Inputs::text($request, 'store'), OpsPermissions::NOTICE_MANAGE) ?? throw new Unauthorized(OpsPermissions::NOTICE_MANAGE);

        return $this->form($choices->forJobs(OpsPermissions::NOTICE_MANAGE), $store->code, null);
    }

    /**
     * O6: Edit Notice, while it waits. One that has started goes back to the list, in words - unless it started while
     * its form was open: then the form stays, with what was typed, the words and the way back (`WrittenRefusals`).
     */
    public function edit(Request $request, string $notice, StoreChoices $choices, ViewNoticeHandler $view): Response|RedirectResponse
    {
        $written = $this->pages->notice($view->handle(new ViewNotice($notice)));
        $errors = $request->session()->get('errors');
        $refused = $errors instanceof ViewErrorBag && $errors->getBag('default')->has('started');

        if (! $written->changeable && ! $refused) {
            return redirect()->route('ops.admin.notices', ['store' => $written->storeCode])->withErrors(['form' => __('ops::errors.notice_already_started.detail')]);
        }

        $store = $choices->chosen($written->storeCode, OpsPermissions::NOTICE_MANAGE) ?? throw new Unauthorized(OpsPermissions::NOTICE_MANAGE);

        return $this->form([$store], $store->code, $written);
    }

    /** The segments the form may pick from (§4.4): a store's, under `ops.notice.manage` there. */
    public function segments(Request $request, StoreChoices $choices, ListSegmentsHandler $list): JsonResponse
    {
        $store = $choices->chosen(Inputs::text($request, 'store'), OpsPermissions::NOTICE_MANAGE) ?? throw new Unauthorized(OpsPermissions::NOTICE_MANAGE);

        return new JsonResponse($this->pages->segments($list->handle(new ListSegments($store->id, OpsPermissions::NOTICE_MANAGE, Inputs::text($request, 'after'))))->toArray());
    }

    public function show(string $notice, ViewNoticeHandler $view): JsonResponse
    {
        return new JsonResponse($this->pages->notice($view->handle(new ViewNotice($notice)))->toArray());
    }

    public function store(Request $request, StoreChoices $choices, CreateNoticeHandler $create, ViewNoticeHandler $view): JsonResponse|RedirectResponse
    {
        $write = function () use ($request, $choices, $create, $view): NoticeData {
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
                Inputs::flag($request, 'draft'),
            ));

            return $this->pages->notice($view->handle(new ViewNotice($id)));
        };

        return Answers::fromPage($request) ? self::toList($request, $write) : new JsonResponse($write()->toArray(), 201);
    }

    public function update(Request $request, string $notice, ChangeNoticeHandler $change, ViewNoticeHandler $view): JsonResponse|RedirectResponse
    {
        $write = function () use ($request, $notice, $change, $view): NoticeData {
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
                Inputs::flag($request, 'draft'),
            ));

            return $this->pages->notice($view->handle(new ViewNotice($notice)));
        };

        return Answers::fromPage($request) ? self::toList($request, $write) : new JsonResponse($write()->toArray());
    }

    public function cancel(Request $request, string $notice, CancelNoticeHandler $cancel, ViewNoticeHandler $view): JsonResponse|RedirectResponse
    {
        if (! Answers::fromPage($request)) {
            $cancel->handle(new CancelNotice($notice));

            return new JsonResponse($this->pages->notice($view->handle(new ViewNotice($notice)))->toArray());
        }

        try {
            $cancel->handle(new CancelNotice($notice));
        } catch (DomainError $error) {
            return WrittenRefusals::back($request, $error);
        }

        return back()->with('status', __('ops::admin_written.notices.cancel.toast'));
    }

    /**
     * @param  list<StoreDto>  $stores
     */
    private function form(array $stores, string $code, ?NoticeData $notice): Response
    {
        $locale = app()->getLocale();

        return $this->page->render('Ops/Admin/Notices/Form', (new NoticeFormPageData(
            array_map(static fn (StoreDto $store): NoticeStoreData => new NoticeStoreData($store->code, $store->name->in($locale), $store->isActive, $store->timezone), $stores),
            $code,
            $notice,
        ))->toArray(), self::WORDS);
    }

    /**
     * Back to the list with what happens next, or back to the form with its words.
     *
     * @param  callable(): NoticeData  $write
     */
    private static function toList(Request $request, callable $write): RedirectResponse
    {
        try {
            $notice = $write();
        } catch (DomainError $error) {
            return WrittenRefusals::back($request, $error);
        }

        return redirect()->route('ops.admin.notices', ['store' => $notice->storeCode])->with('status', match ($notice->status) {
            'DRAFT' => __('ops::admin_written.notices.toast_draft'),
            'SCHEDULED' => __('ops::admin_written.notices.toast_scheduled', ['at' => $notice->sendAtLocal]),
            default => __('ops::admin_written.notices.toast_now'),
        });
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
