<?php

declare(strict_types=1);

namespace Modules\Platform\Presentation\Http\Controller;

use App\Http\FormErrors;
use App\Http\Page;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Response;
use Modules\Platform\Application\Command\UpdateStore\UpdateStore;
use Modules\Platform\Application\Command\UpdateStore\UpdateStoreHandler;
use Modules\Platform\Application\Query\ListStores\ListStoresHandler;
use Modules\Platform\Presentation\Http\Resource\StorePages;
use Shared\Domain\Error\DomainError;

/**
 * The stores screen (frontend.md 3.5, E1 and E2).
 *
 * No store is made here: opening a country stays a console command, so a store is created complete,
 * in one command, and can never exist half-configured [DECIDED 2026-09-19]. The code, the country
 * and the currency cannot be changed either - they are shown, and Platform refuses an attempt to
 * change them rather than ignoring it.
 *
 * This controller checks nothing. Which stores appear, and which of them may be changed, are
 * answered by Platform's read model; the update is refused by its handler, against that one store.
 */
final readonly class StoresController
{
    /** @var list<string> */
    private const array WORDS = ['platform::admin_stores', 'platform::errors', 'access::errors', 'admin'];

    public function __construct(
        private Page $page,
        private StorePages $pages,
    ) {}

    /** E1. */
    public function index(ListStoresHandler $stores): Response
    {
        return $this->page->render('Platform/Admin/Stores/Index', $this->pages->list($stores)->toArray(), self::WORDS);
    }

    /** E2. */
    public function update(Request $request, string $storeCode, UpdateStoreHandler $handler): RedirectResponse
    {
        try {
            $handler->handle(new UpdateStore(
                $storeCode,
                nameAr: $request->string('name_ar')->toString(),
                nameEn: $request->string('name_en')->toString(),
                taxRateBasisPoints: self::basisPoints($request->string('tax_rate')->toString()),
                timezone: $request->string('timezone')->toString(),
                position: $request->integer('position'),
            ));
        } catch (DomainError $error) {
            return FormErrors::back($request, $error, ['name_ar', 'name_en', 'tax_rate', 'timezone', 'position']);
        }

        return back()->with('status', __('platform::admin_stores.saved'));
    }

    /**
     * "15" as 1500, and "15.5" as 1550.
     *
     * Read digit by digit rather than multiplied, for the same reason the screen is given the
     * percentage as a string: a rate is money's neighbour, and turning "15.5" into a float first
     * would hand the domain 1550.0000000000002 to round. A value of any other shape becomes a
     * number the domain refuses, which is where that answer belongs.
     */
    public static function basisPoints(string $percent): int
    {
        [$whole, $fraction] = array_pad(explode('.', trim($percent), 2), 2, '');

        return ((int) $whole) * 100 + (int) str_pad(substr($fraction, 0, 2), 2, '0');
    }
}
