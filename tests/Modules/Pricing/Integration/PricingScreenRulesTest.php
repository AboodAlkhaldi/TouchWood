<?php

declare(strict_types=1);

use App\Http\FormErrors;
use Carbon\CarbonImmutable;
use Database\Seeders\CatalogSeeder;
use Database\Seeders\PlatformSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Modules\Catalog\Application\Command\ArchiveVariant\ArchiveVariant;
use Modules\Catalog\Application\Command\ArchiveVariant\ArchiveVariantHandler;
use Modules\Catalog\Application\Command\ChooseInStore\ChooseInStore;
use Modules\Catalog\Application\Command\ChooseInStore\ChooseInStoreHandler;
use Modules\Catalog\Application\Command\SetSellingTerms\SetSellingTerms;
use Modules\Catalog\Application\Command\SetSellingTerms\SetSellingTermsHandler;
use Modules\Pricing\Application\Command\AddCategoryDiscount\AddCategoryDiscount;
use Modules\Pricing\Application\Command\AddCategoryDiscount\AddCategoryDiscountHandler;
use Modules\Pricing\Application\Command\AddSale\AddSale;
use Modules\Pricing\Application\Command\AddSale\AddSaleHandler;
use Modules\Pricing\Application\Command\ChangeCategoryDiscount\ChangeCategoryDiscount;
use Modules\Pricing\Application\Command\ChangeCategoryDiscount\ChangeCategoryDiscountHandler;
use Modules\Pricing\Application\Command\ChangeSale\ChangeSale;
use Modules\Pricing\Application\Command\ChangeSale\ChangeSaleHandler;
use Modules\Pricing\Application\Command\SetRetailPrice\SetRetailPrice;
use Modules\Pricing\Application\Command\SetRetailPrice\SetRetailPriceHandler;
use Modules\Pricing\Application\Command\SetWholesalePrices\SetWholesalePrices;
use Modules\Pricing\Application\Command\SetWholesalePrices\SetWholesalePricesHandler;
use Modules\Pricing\Domain\Exception\BandsInvalid;
use Modules\Pricing\Domain\Exception\InvalidPricingInput;
use Modules\Pricing\Domain\Exception\NoRetailPrice;
use Modules\Pricing\Domain\Exception\OverCeiling;
use Modules\Pricing\Domain\Exception\PriceNotPositive;
use Modules\Pricing\Domain\Exception\PriceTooPrecise;
use Shared\Domain\Error\DomainError;
use Tests\Modules\Access\Support\AccessFixtures as Fx;
use Tests\Modules\Catalog\Support\CatalogProducts as Px;
use Tests\Modules\Pricing\Support\PricingFixtures as Rx;

use function Pest\Laravel\seed;

/*
|--------------------------------------------------------------------------
| The rules stage 5's screens brought (pricing.md §4.3, §7, §9.4): a batch refusal names its sizes (Q2);
| at most ten bands, none above the wholesale maximum, the rule broken named (Q12); a sale only on a
| product ready and switched on (Q13); "All Sizes" the priced ones (Q39); a sale changed keeps its
| product and sizes; three wordings (Q15).
|--------------------------------------------------------------------------
*/

uses(RefreshDatabase::class);

beforeEach(function () {
    seed(PlatformSeeder::class);
    seed(CatalogSeeder::class);
    CarbonImmutable::setTestNow('2026-10-14 10:00:00+00');
    Queue::fake();
    app()->setLocale('en');
});

afterEach(function () {
    CarbonImmutable::setTestNow();
});

/**
 * The refusal this work met, or null.
 */
function rulesRefusal(callable $work): ?DomainError
{
    try {
        Fx::asSystem($work);
    } catch (DomainError $error) {
        return $error;
    }

    return null;
}

/**
 * @param  array<string, string>  $prices
 */
function rulesPrices(array $prices): void
{
    app(SetRetailPriceHandler::class)->handle(new SetRetailPrice(Rx::storeId('sa'), $prices));
}

/**
 * @param  array<int, string>  $bands
 */
function rulesBands(string $size, array $bands): void
{
    app(SetWholesalePricesHandler::class)->handle(new SetWholesalePrices(Rx::storeId('sa'), $size, $bands));
}

/**
 * @param  list<string>  $sizes
 */
function rulesSale(array $sizes, ?string $product = null, string $percent = '10'): string
{
    return app(AddSaleHandler::class)->handle(new AddSale(Rx::storeId('sa'), $product, $sizes, $percent, null, null, null));
}

describe('a batch refusal names its sizes (Q2)', function () {
    it('names the size whose price is refused, so the screen marks its row', function () {
        [$a, $b] = Px::ready(['60 cm', '80 cm'])['variants'];
        $archived = Px::ready(['60 cm', '80 cm'])['variants'];
        Fx::asSystem(fn () => app(ArchiveVariantHandler::class)->handle(new ArchiveVariant($archived[1])));

        $precise = rulesRefusal(fn () => rulesPrices([$a => '10', $b => '10.123']));
        $zero = rulesRefusal(fn () => rulesPrices([$a => '0', $b => '10']));
        $text = rulesRefusal(fn () => rulesPrices([$a => '10', $b => 'ten']));
        $gone = rulesRefusal(fn () => rulesPrices([$a => '10', $archived[1] => '10']));

        expect($precise)->toBeInstanceOf(PriceTooPrecise::class)
            ->and(array_keys(FormErrors::rows($precise ?? throw new LogicException)))->toBe([$b])
            ->and($zero)->toBeInstanceOf(PriceNotPositive::class)
            ->and(array_keys(FormErrors::rows($zero ?? throw new LogicException)))->toBe([$a])
            ->and($text?->context())->toBe(['attribute' => 'amount', 'variants' => $b])
            ->and($gone?->context())->toBe(['attribute' => 'variant', 'variants' => $archived[1]])
            ->and(DB::table('pricing.retail_prices')->count())->toBe(0);
    });

    it('names the sizes whose new price takes a sale past the ceiling', function () {
        $ready = Px::ready(['60 cm', '80 cm']);
        [$a, $b] = $ready['variants'];
        Fx::asSystem(fn () => rulesPrices([$a => '100', $b => '100']));
        Rx::switchOn('sa', [$a]);
        Fx::asSystem(fn () => app(AddSaleHandler::class)->handle(new AddSale(Rx::storeId('sa'), null, [$a], null, '75', null, null)));

        $over = rulesRefusal(fn () => rulesPrices([$a => '200', $b => '200']));

        expect($over)->toBeInstanceOf(OverCeiling::class)
            ->and(array_keys(FormErrors::rows($over ?? throw new LogicException)))->toBe([$a])
            ->and(FormErrors::message($over))->toContain('Change the price, the offer or the discount');
    });
});

describe('wholesale bands (Q12)', function () {
    it('names the rule a refusal broke - too many, past the maximum, below or off the minimum, out of order, not below retail', function () {
        $ready = Px::ready();
        [$size] = $ready['variants'];
        Fx::asSystem(fn () => app(ChooseInStoreHandler::class)->handle(new ChooseInStore(Rx::storeId('sa'), $ready['product'], true)));
        Fx::asSystem(fn () => app(SetSellingTermsHandler::class)->handle(new SetSellingTerms(Rx::storeId('sa'), $ready['product'], [$size => ['retail' => true, 'wholesale' => true]], 1, null, 10, 100)));
        Fx::asSystem(fn () => rulesPrices([$size => '100']));
        $rule = static fn (array $bands): ?string => ($error = rulesRefusal(fn () => rulesBands($size, $bands))) instanceof BandsInvalid ? $error->rule : null;
        $eleven = [];

        foreach (range(0, 10) as $i) {
            $eleven[10 + $i] = (string) (90 - $i);
        }

        expect($rule($eleven))->toBe(BandsInvalid::TOO_MANY)
            ->and($rule([10 => '90', 101 => '80']))->toBe(BandsInvalid::MAXIMUM)
            ->and($rule([5 => '90']))->toBe(BandsInvalid::MINIMUM)
            ->and($rule([20 => '90']))->toBe(BandsInvalid::FIRST_BAND)
            ->and($rule([10 => '90', 50 => '95']))->toBe(BandsInvalid::ORDER)
            ->and($rule([10 => '100']))->toBe(BandsInvalid::BELOW_RETAIL)
            ->and(rulesRefusal(fn () => rulesBands($size, [10 => '90', 100 => '80'])))->toBeNull();

        $words = rulesRefusal(fn () => rulesBands($size, [20 => '90']));

        expect(FormErrors::message($words ?? throw new LogicException))->toBe('The first band must start at 10, the wholesale minimum.')
            ->and(FormErrors::message(rulesRefusal(fn () => rulesBands($size, [10 => '100'])) ?? throw new LogicException))->toBe('The first band must cost less than 100.00, the retail price.')
            ->and(array_keys(FormErrors::rows($words)))->toBe([$size]);
    });
});

describe('which sizes a sale may take (Q13, Q39)', function () {
    it('takes only a ready product switched on in the store, and only its sizes switched on there', function () {
        $ready = Px::ready(['60 cm', '80 cm']);
        [$a, $b] = $ready['variants'];
        Fx::asSystem(fn () => rulesPrices([$a => '100', $b => '100']));

        // Not switched on in the store: neither the product nor a size.
        expect(rulesRefusal(fn () => rulesSale([], $ready['product']))?->context())->toBe(['attribute' => 'product'])
            ->and(rulesRefusal(fn () => rulesSale([$a]))?->context())->toBe(['attribute' => 'variant', 'variants' => $a]);

        Fx::asSystem(fn () => app(ChooseInStoreHandler::class)->handle(new ChooseInStore(Rx::storeId('sa'), $ready['product'], true, [$a])));

        expect(rulesRefusal(fn () => rulesSale([$a, $b]))?->context())->toBe(['attribute' => 'variant', 'variants' => $b])
            ->and(rulesRefusal(fn () => rulesSale([$a])))->toBeNull();

        // A draft's sizes: refused as its product.
        $draft = Px::product();
        $size = Px::variant($draft, '7000001');

        expect(rulesRefusal(fn () => rulesSale([$size]))?->context())->toBe(['attribute' => 'product']);
    });

    it('takes "All Sizes" as every size switched on with a price, saved as that list, and refuses a product with none priced', function () {
        $ready = Px::ready(['60 cm', '80 cm', '100 cm']);
        [$a, $b, $c] = $ready['variants'];
        Fx::asSystem(fn () => app(ChooseInStoreHandler::class)->handle(new ChooseInStore(Rx::storeId('sa'), $ready['product'], true, [$a, $b])));

        $none = rulesRefusal(fn () => rulesSale([], $ready['product']));

        expect($none)->toBeInstanceOf(NoRetailPrice::class)
            ->and(FormErrors::message($none ?? throw new LogicException))->toBe('This size has no retail price in the store. Give it a price before putting it on any offer or giving it wholesale prices.');

        Fx::asSystem(fn () => rulesPrices([$a => '100', $c => '100']));
        $sale = Fx::asSystem(fn (): string => rulesSale([], $ready['product']));

        expect(DB::table('pricing.sale_variants')->where('sale_id', $sale)->pluck('variant_id')->all())->toBe([$a]);
    });

    it('keeps a changed sale\'s product and sizes as they were', function () {
        $ready = Px::ready(['60 cm', '80 cm']);
        [$a, $b] = $ready['variants'];
        Rx::switchOn('sa', [$a]);
        Fx::asSystem(fn () => rulesPrices([$a => '100', $b => '100']));
        $sale = Fx::asSystem(fn (): string => app(AddSaleHandler::class)->handle(new AddSale(Rx::storeId('sa'), null, [$a], '10', null, '2026-10-20 00:00:00+00', null)));

        Fx::asSystem(fn () => app(ChangeSaleHandler::class)->handle(new ChangeSale($sale, '20', null, '2026-10-21 00:00:00+00', null)));

        expect(DB::table('pricing.sale_variants')->where('sale_id', $sale)->pluck('variant_id')->all())->toBe([$a])
            ->and((string) DB::table('pricing.sales')->where('id', $sale)->value('product_id'))->toBe($ready['product'])
            ->and((int) DB::table('pricing.sales')->where('id', $sale)->value('percent_bp'))->toBe(2000);
    });
});

describe('the products a discount leaves out (Q10)', function () {
    it('takes a product of a child category, keeps one left out already while the category stays, and refuses it in another', function () {
        $kitchens = Px::category('Kitchens');
        $cabinets = Px::category('Cabinets', $kitchens);
        $doors = Px::category('Doors');
        $child = Px::ready(['60 cm'], $cabinets);
        $moved = Px::ready(['60 cm'], Px::category('Shelves', $kitchens));
        $discount = Fx::asSystem(fn (): string => app(AddCategoryDiscountHandler::class)->handle(new AddCategoryDiscount(Rx::storeId('sa'), $kitchens, '10', null, 'BOTH', '2026-10-20 00:00:00+00', null, false, [$child['product'], $moved['product']])));
        $change = static fn (string $category): ?DomainError => rulesRefusal(fn () => app(ChangeCategoryDiscountHandler::class)->handle(new ChangeCategoryDiscount($discount, $category, '10', null, 'BOTH', '2026-10-20 00:00:00+00', null, false, [$moved['product']])));

        // The product left out moves out of the category since.
        DB::table('catalog.products')->where('id', $moved['product'])->update(['category_id' => $doors]);

        expect(DB::table('pricing.category_discount_exclusions')->where('discount_id', $discount)->count())->toBe(2)
            ->and($change($kitchens))->toBeNull()
            // A new category leaves out only its own products.
            ->and($change($cabinets)?->context())->toBe(['attribute' => 'left_out']);
    });
});

describe('the wordings (Q15)', function () {
    it('names "offers and discounts" on a lower ceiling, and calls a category قسم in the Arabic audit log', function () {
        $en = require dirname(__DIR__, 4).'/src/Modules/Pricing/Presentation/lang/en/errors.php';
        $audit = require dirname(__DIR__, 4).'/src/Modules/Pricing/Presentation/lang/ar/audit.php';

        expect($en['ceiling_below_sales']['detail'])->toContain('offers and discounts')
            ->and($en['over_ceiling']['detail'])->toContain('the price, the offer or the discount')
            ->and(array_filter($audit, static fn (string $words): bool => str_contains($words, 'فئة')))->toBe([])
            ->and($audit['category_discount.added'])->toContain('قسم');
    });

    it('has the same placeholders in both languages for every band rule', function () {
        $ar = require dirname(__DIR__, 4).'/src/Modules/Pricing/Presentation/lang/ar/errors.php';
        $en = require dirname(__DIR__, 4).'/src/Modules/Pricing/Presentation/lang/en/errors.php';
        $placeholders = static function (string $text): array {
            preg_match_all('/:[a-z_]+/', $text, $found);
            sort($found[0]);

            return $found[0];
        };

        expect(array_keys($en['bands_invalid']['rules']))->toBe([BandsInvalid::FIRST_BAND, BandsInvalid::MINIMUM, BandsInvalid::MAXIMUM, BandsInvalid::ORDER, BandsInvalid::BELOW_RETAIL, BandsInvalid::TOO_MANY])
            ->and(array_keys($ar['bands_invalid']['rules']))->toBe(array_keys($en['bands_invalid']['rules']));

        foreach ($en['bands_invalid']['rules'] as $rule => $words) {
            expect($placeholders($ar['bands_invalid']['rules'][$rule]))->toBe($placeholders($words));
        }
    });

    it('carries the sizes an invalid input concerns only when it concerns some', function () {
        expect((new InvalidPricingInput('store'))->context())->toBe(['attribute' => 'store'])
            ->and((new PriceTooPrecise(2, ['x']))->context())->toBe(['decimals' => 2, 'variants' => 'x']);
    });
});
