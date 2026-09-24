<?php

declare(strict_types=1);

namespace Modules\Platform\Application\Query\ListCurrencies;

use Modules\Platform\Application\Query\StoreDirectory;
use Modules\Platform\Public\PlatformPermissions;
use Shared\Application\Authorizer;
use Shared\Application\PermissionScope;
use Shared\Application\Unauthorized;

/**
 * Every currency, for the one screen that manages them (frontend.md 3.5, E3).
 *
 * Currencies belong to no store - a currency is the system's, not a shop's - so the question is
 * asked globally. Creating and changing one are **reserved** permissions (platform.md 3), which
 * means no role can carry them and a Super Admin is the only person who holds them. Whoever may
 * change a currency may read the list; nobody else has any business on this screen, so there is no
 * separate permission for looking.
 */
final readonly class ListCurrenciesHandler
{
    public const string PERMISSION = PlatformPermissions::CURRENCY_UPDATE;

    public function __construct(
        private Authorizer $authorizer,
        private StoreDirectory $directory,
    ) {}

    /**
     * @return list<CurrencySummary>
     *
     * @throws Unauthorized
     */
    public function handle(ListCurrencies $query): array
    {
        $this->authorizer->authorize(self::PERMISSION, PermissionScope::global());

        $counts = $this->directory->storeCountByCurrency();
        $summaries = [];

        foreach ($this->directory->currencies() as $currency) {
            $inUse = $counts[$currency->code] ?? 0;

            $summaries[] = new CurrencySummary(
                $currency->code,
                $currency->exponent,
                $currency->name->ar,
                $currency->name->en,
                $currency->abbreviation->ar,
                $currency->abbreviation->en,
                $currency->sign,
                $inUse,
                $inUse > 0,
            );
        }

        return $summaries;
    }
}
