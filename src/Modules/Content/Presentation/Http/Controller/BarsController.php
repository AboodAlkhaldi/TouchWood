<?php

declare(strict_types=1);

namespace Modules\Content\Presentation\Http\Controller;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Response;
use Modules\Content\Application\Command\DeleteSmartBar\DeleteSmartBar;
use Modules\Content\Application\Command\DeleteSmartBar\DeleteSmartBarHandler;
use Modules\Content\Application\Command\SaveBar\SaveBar;
use Modules\Content\Application\Command\SaveBar\SaveBarHandler;
use Modules\Content\Application\Command\SaveSmartBar\SaveSmartBar;
use Modules\Content\Application\Command\SaveSmartBar\SaveSmartBarHandler;
use Modules\Content\Application\ContentPermissions;
use Modules\Content\Application\Query\Bars\BarsHandler;
use Modules\Content\Application\Query\ContentActions\ContentActionsForReader;
use Modules\Content\Presentation\Http\Resource\BarData;
use Modules\Content\Presentation\Http\Resource\BarsPageData;
use Modules\Content\Presentation\Http\Resource\SmartBarData;
use Shared\Domain\Error\DomainError;

/*
| W2 · Bars (content.md §4.4, §1.5, §1.9): the store's announcement bar and its smart bars, both under
| `content.bar.manage`. A plain visit gets the page; a screen's own read the JSON. A save the page sends goes back with
| its toast, or to its box with its refusal.
*/
final readonly class BarsController
{
    /** @var list<string> */
    private const array WORDS = ['content::admin_bars'];

    /** @var list<string> */
    private const array BAR_BOXES = ['text_ar', 'text_en', 'link', 'starts_at', 'ends_at'];

    /** @var list<string> */
    private const array SMART_BOXES = ['message_ar', 'message_en', 'link', 'category_id', 'run_id', 'scope', 'starts_at', 'ends_at'];

    public function __construct(
        private ContentScreens $screens,
    ) {}

    public function index(Request $request, BarsHandler $bars, ContentActionsForReader $actions): Response|JsonResponse
    {
        $store = $this->screens->store($request, ContentPermissions::BAR_MANAGE);
        $bar = BarData::fromFields($bars->bar($store->id));
        $smart = array_map(SmartBarData::fromRow(...), $bars->smartBars($store->id));

        if ($request->expectsJson()) {
            return new JsonResponse(['bar' => $bar->toArray(), 'smartBars' => array_map(static fn (SmartBarData $row): array => $row->toArray(), $smart)]);
        }

        return $this->screens->render('Content/Admin/Bars', (new BarsPageData(
            $this->screens->stores(ContentPermissions::BAR_MANAGE),
            $store->code,
            $store->timezone,
            Inputs::text($request, 'tab') === 'smart' ? 'smart' : 'announcement',
            $bar,
            $smart,
            $this->screens->categories(),
            $actions->translationReady(),
        ))->toArray(), self::WORDS);
    }

    public function saveBar(Request $request, SaveBarHandler $save): RedirectResponse
    {
        try {
            $save->handle(new SaveBar(
                Inputs::text($request, 'store'),
                Inputs::version($request),
                Inputs::typed($request, 'text_ar'),
                Inputs::typed($request, 'text_en'),
                Inputs::text($request, 'link'),
                Inputs::flag($request, 'is_on'),
                Inputs::text($request, 'starts_at'),
                Inputs::text($request, 'ends_at'),
            ));
        } catch (DomainError $error) {
            return Refusals::back($request, $error, self::BAR_BOXES);
        }

        return back()->with('status', __('content::admin_bars.bar.saved'));
    }

    public function saveSmartBar(Request $request, SaveSmartBarHandler $save, ?string $smartBar = null): RedirectResponse
    {
        try {
            $save->handle(new SaveSmartBar(
                $smartBar,
                Inputs::text($request, 'store'),
                (string) Inputs::text($request, 'scope'),
                Inputs::text($request, 'category_id'),
                Inputs::text($request, 'run_id'),
                Inputs::typed($request, 'message_ar'),
                Inputs::typed($request, 'message_en'),
                Inputs::text($request, 'link'),
                Inputs::text($request, 'starts_at'),
                Inputs::text($request, 'ends_at'),
            ));
        } catch (DomainError $error) {
            return Refusals::back($request, $error, self::SMART_BOXES);
        }

        return back()->with('status', __('content::admin_bars.smart.saved'));
    }

    public function deleteSmartBar(Request $request, string $smartBar, DeleteSmartBarHandler $delete): RedirectResponse
    {
        try {
            $delete->handle(new DeleteSmartBar($smartBar));
        } catch (DomainError $error) {
            return Refusals::back($request, $error, []);
        }

        return back()->with('status', __('content::admin_bars.smart.deleted'));
    }
}
