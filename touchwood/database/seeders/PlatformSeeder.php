<?php

declare(strict_types=1);

namespace Database\Seeders;

use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\Seeder;
use Modules\Platform\Application\Command\ActivateStore\ActivateStore;
use Modules\Platform\Application\Command\ActivateStore\ActivateStoreHandler;
use Modules\Platform\Application\Command\CreateCurrency\CreateCurrency;
use Modules\Platform\Application\Command\CreateCurrency\CreateCurrencyHandler;
use Modules\Platform\Application\Command\CreateStore\CreateStore;
use Modules\Platform\Application\Command\CreateStore\CreateStoreHandler;
use Modules\Platform\Application\Query\StoreDirectory;
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
 *
 * **On, and KSA the base store** (platform.md §1.1, §5.2; owner, 2026-10-01 and 2026-10-02). A store
 * is created off; the launch stores are live, so each one this seeder creates is turned on at once,
 * as the migration turns on the stores an installation already had. A store that already existed is
 * left as it is — running the seeder again never undoes a Super Admin's switch. The base mark goes on
 * KSA when no store carries it yet; it is set here and by the migration, and never moves.
 */
final class PlatformSeeder extends Seeder
{
    /** The base store's code. A seeder may name a store; Domain/ and Application/ never do. */
    private const string BASE_STORE = 'sa';

    public function run(
        CurrencyRepository $existingCurrencies,
        StoreRepository $existingStores,
        CreateCurrencyHandler $currencies,
        CreateStoreHandler $stores,
        ActivateStoreHandler $activate,
        StoreDirectory $directory,
        ConnectionInterface $db,
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
            new CreateStore(self::BASE_STORE, 'السعودية', 'Saudi Arabia', 'SA', 'SAR', 1500, 'Asia/Riyadh', 1),
            new CreateStore('eg', 'مصر', 'Egypt', 'EG', 'EGP', 1400, 'Africa/Cairo', 2),
            new CreateStore('ae', 'الإمارات', 'United Arab Emirates', 'AE', 'AED', 500, 'Asia/Dubai', 3),
        ];

        foreach ($seedStores as $store) {
            if (! $existingStores->codeExists(StoreCode::fromString($store->code))) {
                $stores->handle($store);
                $activate->handle(new ActivateStore($store->code));
            }
        }

        $this->markTheBaseStore($db, $directory);
    }

    /**
     * KSA, when no store is the base store yet. Only a store that is on can carry the mark (the
     * `stores_base_always_active` CHECK); the launch store is on unless somebody turned it off before
     * any store was the base, and then nothing is marked rather than turning it back on behind them.
     */
    private function markTheBaseStore(ConnectionInterface $db, StoreDirectory $directory): void
    {
        $db->transaction(function () use ($db, $directory): void {
            if ($db->table('platform.stores')->where('is_base', true)->exists()) {
                return;
            }

            $marked = $db->table('platform.stores')
                ->where('code', self::BASE_STORE)
                ->where('is_active', true)
                ->update(['is_base' => true, 'updated_at' => now()]);

            if ($marked > 0) {
                $directory->invalidate();
            }
        });
    }
}
