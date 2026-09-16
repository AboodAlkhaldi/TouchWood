<?php

declare(strict_types=1);

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Modules\Platform\Application\Command\CreateCurrency\CreateCurrency;
use Modules\Platform\Application\Command\CreateCurrency\CreateCurrencyHandler;
use Modules\Platform\Application\Command\CreateStore\CreateStore;
use Modules\Platform\Application\Command\CreateStore\CreateStoreHandler;
use Modules\Platform\Domain\Repository\CurrencyRepository;
use Modules\Platform\Domain\Repository\StoreRepository;
use Modules\Platform\Domain\ValueObject\CurrencyCode;
use Modules\Platform\Domain\ValueObject\StoreCode;

/**
 * The three launch stores and their currencies (Platform spec §5.7). Country and currency
 * values live here as data — never in Domain/ or Application/. Safe to run more than once.
 *
 * What already exists is read from the database, never the cache: a cache that outlived a
 * wiped database would otherwise make the seeder skip everything.
 */
final class PlatformSeeder extends Seeder
{
    public function run(
        CurrencyRepository $existingCurrencies,
        StoreRepository $existingStores,
        CreateCurrencyHandler $currencies,
        CreateStoreHandler $stores,
    ): void {
        $seedCurrencies = [
            // Saudi Riyal sign U+20C1 (Unicode 17.0).
            new CreateCurrency('SAR', 2, 'ريال سعودي', 'Saudi Riyal', 'ر.س', 'SAR', "\u{20C1}"),
            // Egypt has no official currency sign; prices show the letters.
            new CreateCurrency('EGP', 2, 'جنيه مصري', 'Egyptian Pound', 'ج.م', 'EGP', null),
            // UAE Dirham sign U+20C3 (Unicode 18.0).
            new CreateCurrency('AED', 2, 'درهم إماراتي', 'UAE Dirham', 'د.إ', 'AED', "\u{20C3}"),
        ];

        foreach ($seedCurrencies as $currency) {
            if (! $existingCurrencies->exists(CurrencyCode::fromString($currency->code))) {
                $currencies->handle($currency);
            }
        }

        $seedStores = [
            new CreateStore('sa', 'السعودية', 'Saudi Arabia', 'SA', 'SAR', 1500, 'Asia/Riyadh', 1),
            new CreateStore('eg', 'مصر', 'Egypt', 'EG', 'EGP', 1400, 'Africa/Cairo', 2),
            new CreateStore('ae', 'الإمارات', 'United Arab Emirates', 'AE', 'AED', 500, 'Asia/Dubai', 3),
        ];

        foreach ($seedStores as $store) {
            if (! $existingStores->codeExists(StoreCode::fromString($store->code))) {
                $stores->handle($store);
            }
        }
    }
}
