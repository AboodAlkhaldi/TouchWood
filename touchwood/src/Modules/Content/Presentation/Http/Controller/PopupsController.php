<?php

declare(strict_types=1);

namespace Modules\Content\Presentation\Http\Controller;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Response;
use Modules\Content\Application\Command\DeletePopup\DeletePopup;
use Modules\Content\Application\Command\DeletePopup\DeletePopupHandler;
use Modules\Content\Application\Command\EndPopup\EndPopup;
use Modules\Content\Application\Command\EndPopup\EndPopupHandler;
use Modules\Content\Application\Command\PopupBackToDraft\PopupBackToDraft;
use Modules\Content\Application\Command\PopupBackToDraft\PopupBackToDraftHandler;
use Modules\Content\Application\Command\SavePopup\SavePopup;
use Modules\Content\Application\Command\SavePopup\SavePopupHandler;
use Modules\Content\Application\Command\SchedulePopup\SchedulePopup;
use Modules\Content\Application\Command\SchedulePopup\SchedulePopupHandler;
use Modules\Content\Application\ContentPermissions;
use Modules\Content\Application\Query\ContentActions\ContentActionsForReader;
use Modules\Content\Application\Query\Popups\PopupsHandler;
use Modules\Content\Application\Query\ScreenReads;
use Modules\Content\Presentation\Http\Resource\ContentStoreData;
use Modules\Content\Presentation\Http\Resource\PopupData;
use Modules\Content\Presentation\Http\Resource\PopupFormPageData;
use Modules\Content\Presentation\Http\Resource\PopupRowData;
use Modules\Content\Presentation\Http\Resource\PopupsPageData;
use Modules\Platform\Public\Contracts\PlatformApi;
use Modules\Platform\Public\Dto\StoreDto;
use Shared\Domain\Error\DomainError;
use Shared\Domain\ValueObject\StoreId;

/*
| W6 · Pop-Ups (content.md §4.4, §1.10): the store's list, a pop-up's own page (New Pop-Up, and each one's), and what its
| menu does - End Now, Back to Draft, Delete. Under `content.popup.manage` in the store, which each handler asks again. A
| plain visit gets the page and the list's Show More the JSON; a change the page sends goes back with its toast, or to
| its box with its refusal.
*/
final readonly class PopupsController
{
    /** @var list<string> */
    private const array WORDS = ['content::admin_popups'];

    /** @var list<string> */
    private const array BOXES = ['name', 'title_ar', 'title_en', 'text_ar', 'text_en', 'button_ar', 'button_en', 'link', 'delay_seconds', 'image', 'starts_at', 'ends_at'];

    private const string LIST = '/admin/content/popups';

    public function __construct(
        private ContentScreens $screens,
    ) {}

    public function index(Request $request, PopupsHandler $popups): Response|JsonResponse
    {
        $store = $this->screens->store($request, ContentPermissions::POPUP_MANAGE);
        $rows = array_map(PopupRowData::fromRow(...), $popups->list($store->id, Inputs::text($request, 'after')));
        $next = count($rows) === ScreenReads::PAGE ? $rows[count($rows) - 1]->id : null;

        if ($request->expectsJson()) {
            return new JsonResponse(['popups' => array_map(static fn (PopupRowData $row): array => $row->toArray(), $rows), 'next' => $next]);
        }

        return $this->screens->render('Content/Admin/Popups/Index', (new PopupsPageData(
            $this->screens->stores(ContentPermissions::POPUP_MANAGE),
            $store->code,
            $store->timezone,
            $rows,
            $next,
        ))->toArray(), self::WORDS);
    }

    public function create(Request $request, ContentActionsForReader $actions): Response
    {
        $store = $this->screens->store($request, ContentPermissions::POPUP_MANAGE);

        return $this->form($store, null, $actions);
    }

    public function edit(string $popup, PopupsHandler $popups, PlatformApi $platform, ContentActionsForReader $actions): Response
    {
        $read = $popups->popup($popup);
        $store = $platform->store(StoreId::fromString((string) $read['store_id']));

        /** @var StoreDto $store the handler found the pop-up in it */
        return $this->form($store, PopupData::fromFields($read), $actions);
    }

    public function store(Request $request, SavePopupHandler $save, SchedulePopupHandler $schedule, PopupsHandler $popups): RedirectResponse
    {
        return $this->save($request, null, $save, $schedule, $popups);
    }

    public function update(Request $request, string $popup, SavePopupHandler $save, SchedulePopupHandler $schedule, PopupsHandler $popups): RedirectResponse
    {
        return $this->save($request, $popup, $save, $schedule, $popups);
    }

    public function end(Request $request, string $popup, EndPopupHandler $end): RedirectResponse
    {
        return self::act($request, fn () => $end->handle(new EndPopup($popup)), 'content::admin_popups.ended');
    }

    public function draft(Request $request, string $popup, PopupBackToDraftHandler $draft): RedirectResponse
    {
        return self::act($request, fn () => $draft->handle(new PopupBackToDraft($popup)), 'content::admin_popups.drafted');
    }

    public function delete(Request $request, string $popup, DeletePopupHandler $delete): RedirectResponse
    {
        return self::act($request, fn () => $delete->handle(new DeletePopup($popup)), 'content::admin_popups.deleted');
    }

    /**
     * Save Draft, or Schedule Pop-Up: the pop-up saved, then scheduled at the version the save made. A new one whose
     * Schedule is refused stays a draft, and its own page says why.
     */
    private function save(Request $request, ?string $id, SavePopupHandler $save, SchedulePopupHandler $schedule, PopupsHandler $popups): RedirectResponse
    {
        try {
            $saved = $save->handle(new SavePopup(
                $id,
                Inputs::text($request, 'store'),
                Inputs::version($request),
                (string) Inputs::typed($request, 'name'),
                Inputs::typed($request, 'title_ar'),
                Inputs::typed($request, 'title_en'),
                Inputs::typed($request, 'text_ar'),
                Inputs::typed($request, 'text_en'),
                Inputs::typed($request, 'button_ar'),
                Inputs::typed($request, 'button_en'),
                Inputs::text($request, 'link'),
                Inputs::number($request, 'delay_seconds'),
                Inputs::text($request, 'media_id'),
                Inputs::text($request, 'starts_at'),
                Inputs::text($request, 'ends_at'),
                Inputs::flag($request, 'translated'),
                Inputs::flag($request, 'read'),
            ));
        } catch (DomainError $error) {
            return Refusals::back($request, $error, self::BOXES);
        }

        $read = $popups->popup($saved);
        $code = $this->code((string) $read['store_id']);

        if (Inputs::text($request, 'intent') === 'schedule' && $read['is_scheduled'] !== true) {
            try {
                $schedule->handle(new SchedulePopup($saved, (int) $read['version'], Inputs::flag($request, 'read')));
            } catch (DomainError $error) {
                return Refusals::to(self::LIST."/{$saved}", $error, self::BOXES);
            }

            return redirect(self::LIST.'?store='.$code)->with('status', __('content::admin_popups.scheduled'));
        }

        return redirect(self::LIST.'?store='.$code)->with('status', __('content::admin_popups.saved'));
    }

    private function form(StoreDto $store, ?PopupData $popup, ContentActionsForReader $actions): Response
    {
        $image = $popup?->mediaId === null ? null : ($this->screens->images([$popup->mediaId])[$popup->mediaId] ?? null);

        return $this->screens->render('Content/Admin/Popups/Form', (new PopupFormPageData(
            ContentStoreData::of($store),
            $store->timezone,
            $popup,
            $image,
            $actions->translationReady(),
        ))->toArray(), self::WORDS);
    }

    private function code(string $storeId): string
    {
        foreach ($this->screens->stores(ContentPermissions::POPUP_MANAGE) as $store) {
            if ($store->id === strtolower($storeId)) {
                return $store->code;
            }
        }

        return '';
    }

    /**
     * @param  callable(): void  $change
     */
    private static function act(Request $request, callable $change, string $toast): RedirectResponse
    {
        try {
            $change();
        } catch (DomainError $error) {
            return Refusals::back($request, $error, []);
        }

        return back()->with('status', __($toast));
    }
}
