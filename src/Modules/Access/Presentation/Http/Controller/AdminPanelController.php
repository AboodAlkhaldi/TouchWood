<?php

declare(strict_types=1);

namespace Modules\Access\Presentation\Http\Controller;

use App\Http\FormErrors;
use App\Http\Page;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Response;
use Modules\Access\Application\Command\ChooseCurrentStore\ChooseCurrentStore;
use Modules\Access\Application\Command\ChooseCurrentStore\ChooseCurrentStoreHandler;
use Modules\Platform\Public\Contracts\PlatformApi;
use Shared\Domain\Error\DomainError;
use Shared\Domain\ValueObject\StoreId;

/**
 * The panel itself: where it opens, and the two choices a person makes about it (frontend.md §2.2).
 *
 * The home page carries no figures — every number an admin home would show belongs to a module that
 * is not built. It exists so that the frame, the menu and signing out are real rather than
 * described.
 */
final readonly class AdminPanelController
{
    public function __construct(
        private Page $page,
    ) {}

    public function home(): Response
    {
        return $this->page->render('Admin/Home', [], ['admin', 'access::auth']);
    }

    /**
     * Which store the panel is working in. A preference, never a permission: the handler refuses any
     * store that is not theirs, and every screen still scopes itself by what they may do.
     */
    public function chooseStore(Request $request, ChooseCurrentStoreHandler $handler, PlatformApi $platform): RedirectResponse
    {
        $storeId = $request->string('store')->toString();

        try {
            $handler->handle(new ChooseCurrentStore($storeId));
        } catch (DomainError $error) {
            return FormErrors::back($request, $error, ['store']);
        }

        $store = $platform->store(StoreId::fromString($storeId));

        return back()->with('status', __('admin.store.changed', [
            // The handler above refused anything that is not theirs, so the store is real here;
            // the name is still read defensively, because a message is not worth an error page.
            'store' => $store === null ? '' : $store->name->in(app()->getLocale()),
        ]));
    }
}
