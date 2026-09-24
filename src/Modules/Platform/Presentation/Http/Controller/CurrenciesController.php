<?php

declare(strict_types=1);

namespace Modules\Platform\Presentation\Http\Controller;

use App\Http\FormErrors;
use App\Http\Page;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Response;
use Modules\Platform\Application\Command\CreateCurrency\CreateCurrency;
use Modules\Platform\Application\Command\CreateCurrency\CreateCurrencyHandler;
use Modules\Platform\Application\Command\UpdateCurrency\UpdateCurrency;
use Modules\Platform\Application\Command\UpdateCurrency\UpdateCurrencyHandler;
use Modules\Platform\Application\Query\ListCurrencies\ListCurrenciesHandler;
use Modules\Platform\Presentation\Http\Resource\CurrencyPages;
use Shared\Domain\Error\DomainError;

/**
 * The currencies screen (frontend.md 3.5, E3).
 *
 * Created and edited in the panel, by a Super Admin alone [DECIDED 2026-09-19]: both permissions
 * are reserved (platform.md 3), so no role can carry them and nobody else reaches this screen.
 *
 * This controller checks nothing. The read model refuses whoever may not be here, and each handler
 * refuses again before it writes.
 */
final readonly class CurrenciesController
{
    /** @var list<string> */
    private const array WORDS = ['platform::admin_currencies', 'platform::errors', 'access::errors', 'admin'];

    /** @var list<string> */
    private const array FIELDS = ['code', 'exponent', 'name_ar', 'name_en', 'abbreviation_ar', 'abbreviation_en', 'sign'];

    public function __construct(
        private Page $page,
        private CurrencyPages $pages,
    ) {}

    /** E3. */
    public function index(ListCurrenciesHandler $currencies): Response
    {
        return $this->page->render(
            'Platform/Admin/Currencies/Index',
            $this->pages->list($currencies)->toArray(),
            self::WORDS,
        );
    }

    public function store(Request $request, CreateCurrencyHandler $handler): RedirectResponse
    {
        try {
            $handler->handle(new CreateCurrency(
                $request->string('code')->toString(),
                $request->integer('exponent'),
                $request->string('name_ar')->toString(),
                $request->string('name_en')->toString(),
                $request->string('abbreviation_ar')->toString(),
                $request->string('abbreviation_en')->toString(),
                $this->sign($request),
            ));
        } catch (DomainError $error) {
            return FormErrors::back($request, $error, self::FIELDS);
        }

        return back()->with('status', __('platform::admin_currencies.created'));
    }

    public function update(Request $request, string $code, UpdateCurrencyHandler $handler): RedirectResponse
    {
        $sign = $this->sign($request);

        try {
            $handler->handle(new UpdateCurrency(
                $code,
                // Sent only when the screen offered it: a currency any store charges in has its
                // decimal places settled, and the form shows them as settled rather than sending
                // the old number back for the handler to compare (platform.md 1.2).
                exponent: $request->has('exponent') ? $request->integer('exponent') : null,
                nameAr: $request->string('name_ar')->toString(),
                nameEn: $request->string('name_en')->toString(),
                abbreviationAr: $request->string('abbreviation_ar')->toString(),
                abbreviationEn: $request->string('abbreviation_en')->toString(),
                sign: $sign,
                // An empty sign field is an instruction, not a missing answer: prices fall back to
                // the abbreviation.
                clearSign: $sign === null,
            ));
        } catch (DomainError $error) {
            return FormErrors::back($request, $error, self::FIELDS);
        }

        return back()->with('status', __('platform::admin_currencies.saved'));
    }

    private function sign(Request $request): ?string
    {
        $sign = trim($request->string('sign')->toString());

        return $sign === '' ? null : $sign;
    }
}
