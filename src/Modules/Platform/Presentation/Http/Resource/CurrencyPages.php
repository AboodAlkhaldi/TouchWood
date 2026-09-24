<?php

declare(strict_types=1);

namespace Modules\Platform\Presentation\Http\Resource;

use Illuminate\Contracts\Foundation\Application;
use Modules\Platform\Application\Query\ListCurrencies\CurrencySummary;
use Modules\Platform\Application\Query\ListCurrencies\ListCurrencies;
use Modules\Platform\Application\Query\ListCurrencies\ListCurrenciesHandler;
use Modules\Platform\Domain\Model\Currency;

/**
 * Platform's currency reads, in the shape the screen wants (frontend.md 3.5, E3).
 *
 * It decides nothing: who may open this screen at all is the handler's answer, and whether an
 * exponent may still be changed is the domain's. What happens here is arrangement.
 */
final readonly class CurrencyPages
{
    public function __construct(
        private Application $app,
    ) {}

    /** E3. */
    public function list(ListCurrenciesHandler $handler): CurrenciesPage
    {
        return new CurrenciesPage(
            array_map($this->row(...), $handler->handle(new ListCurrencies)),
            // From the domain's own limit, so the screen can never offer a number it would refuse.
            range(0, Currency::MAX_EXPONENT),
        );
    }

    private function row(CurrencySummary $currency): CurrencyRow
    {
        return new CurrencyRow(
            $currency->code,
            $this->locale() === 'en' ? $currency->nameEn : $currency->nameAr,
            $currency->nameAr,
            $currency->nameEn,
            $currency->abbreviationAr,
            $currency->abbreviationEn,
            $currency->sign,
            $currency->exponent,
            $currency->storeCount,
            $currency->exponentLocked,
        );
    }

    private function locale(): string
    {
        return $this->app->getLocale() === 'en' ? 'en' : 'ar';
    }
}
