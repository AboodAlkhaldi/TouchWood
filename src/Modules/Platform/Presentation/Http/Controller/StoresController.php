<?php

declare(strict_types=1);

namespace Modules\Platform\Presentation\Http\Controller;

use App\Http\FormErrors;
use App\Http\Page;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Response;
use Modules\Platform\Application\Command\ActivateStore\ActivateStore;
use Modules\Platform\Application\Command\ActivateStore\ActivateStoreHandler;
use Modules\Platform\Application\Command\CreateCurrency\CreateCurrency;
use Modules\Platform\Application\Command\CreateStore\CreateStore;
use Modules\Platform\Application\Command\CreateStore\CreateStoreHandler;
use Modules\Platform\Application\Command\DeactivateStore\DeactivateStore;
use Modules\Platform\Application\Command\DeactivateStore\DeactivateStoreHandler;
use Modules\Platform\Application\Command\UpdateStore\UpdateStore;
use Modules\Platform\Application\Command\UpdateStore\UpdateStoreHandler;
use Modules\Platform\Application\Query\ListStores\ListStoresHandler;
use Modules\Platform\Presentation\Http\Resource\StorePages;
use Shared\Domain\Error\DomainError;

/**
 * The stores screen (frontend.md 3.5, E1 and E2), and Add Store (platform.md §9.7 #3; owner,
 * 2026-10-06, replacing "a console command only").
 *
 * A store is still created complete, in one step, and can never exist half-configured: the form
 * sends everything at once, with its currency either one no store uses or a new one made in the same
 * transaction (one currency, one store, §9.7 #4). The code, the country and the currency cannot be
 * changed afterwards - they are shown, and Platform refuses an attempt to change them rather than
 * ignoring it.
 *
 * This controller checks nothing. Which stores appear, and which of them may be changed, are
 * answered by Platform's read model; the update is refused by its handler, against that one store.
 */
final readonly class StoresController
{
    /** @var list<string> */
    private const array WORDS = ['platform::admin_stores', 'platform::admin_currencies', 'platform::errors', 'access::errors', 'admin'];

    /** @var list<string> Add Store's fields, kept in the form when it is refused. */
    private const array NEW_STORE_FIELDS = [
        'code', 'name_ar', 'name_en', 'country', 'currency', 'tax_rate', 'timezone', 'position', 'new_currency',
        'currency_code', 'currency_exponent', 'currency_name_ar', 'currency_name_en', 'currency_abbreviation_ar', 'currency_abbreviation_en', 'currency_sign',
    ];

    public function __construct(
        private Page $page,
        private StorePages $pages,
    ) {}

    /** E1. */
    public function index(ListStoresHandler $stores): Response
    {
        return $this->page->render('Platform/Admin/Stores/Index', $this->pages->list($stores)->toArray(), self::WORDS);
    }

    /**
     * Add Store. The store opens switched off, to be prepared and turned on (§1.6); the form's
     * fields come back with a refusal, so nothing typed is lost.
     */
    public function store(Request $request, CreateStoreHandler $handler): RedirectResponse
    {
        $nameEn = $request->string('name_en')->toString();

        try {
            $handler->handle(new CreateStore(
                strtolower(trim($request->string('code')->toString())),
                $request->string('name_ar')->toString(),
                $nameEn,
                strtoupper(trim($request->string('country')->toString())),
                strtoupper(trim($request->string('currency')->toString())),
                self::basisPoints($request->string('tax_rate')->toString()),
                $request->string('timezone')->toString(),
                $request->integer('position'),
                $request->boolean('new_currency') ? new CreateCurrency(
                    strtoupper(trim($request->string('currency_code')->toString())),
                    $request->integer('currency_exponent', 2),
                    $request->string('currency_name_ar')->toString(),
                    $request->string('currency_name_en')->toString(),
                    $request->string('currency_abbreviation_ar')->toString(),
                    $request->string('currency_abbreviation_en')->toString(),
                    trim($request->string('currency_sign')->toString()) === '' ? null : trim($request->string('currency_sign')->toString()),
                ) : null,
            ));
        } catch (DomainError $error) {
            return FormErrors::back($request, $error, self::NEW_STORE_FIELDS);
        }

        $name = app()->getLocale() === 'ar' ? $request->string('name_ar')->toString() : $nameEn;

        return back()->with('status', __('platform::admin_stores.created', ['name' => $name]));
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
     * The on/off switch (platform.md §3; owner, 2026-10-01). Super Admin only: the handler asks for
     * the reserved `platform.store.switch`, and refuses to turn the base store off.
     */
    public function activate(Request $request, string $storeCode, ActivateStoreHandler $handler): RedirectResponse
    {
        try {
            $handler->handle(new ActivateStore($storeCode));
        } catch (DomainError $error) {
            return FormErrors::back($request, $error);
        }

        return back()->with('status', __('platform::admin_stores.turned_on'));
    }

    public function deactivate(Request $request, string $storeCode, DeactivateStoreHandler $handler): RedirectResponse
    {
        try {
            $handler->handle(new DeactivateStore($storeCode));
        } catch (DomainError $error) {
            return FormErrors::back($request, $error);
        }

        return back()->with('status', __('platform::admin_stores.turned_off'));
    }

    /**
     * "15" as 1500, and "15.5" as 1550.
     *
     * Read digit by digit rather than multiplied, for the same reason the screen is given the
     * percentage as a string: a rate is money's neighbour, and turning "15.5" into a float first
     * would hand the domain 1550.0000000000002 to round. A value of any other shape - "abc", "15,5",
     * nothing - becomes -1, a number the domain refuses, which is where that answer belongs; read as
     * it was, it became 0 or 15, a rate nobody wrote (the review of P7).
     */
    public static function basisPoints(string $percent): int
    {
        // The Arabic decimal separator is the same point (frontend.md §1.8: a rate typed on an
        // Arabic keyboard is the same rate; its digits are made Latin as they are typed).
        $percent = str_replace("\u{066B}", '.', trim($percent));

        if (preg_match('/\A\d+(\.\d*)?\z/', $percent) !== 1) {
            return -1;
        }

        [$whole, $fraction] = array_pad(explode('.', $percent, 2), 2, '');

        return ((int) $whole) * 100 + (int) str_pad(substr($fraction, 0, 2), 2, '0');
    }
}
