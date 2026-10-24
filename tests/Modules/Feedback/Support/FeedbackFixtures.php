<?php

declare(strict_types=1);

namespace Tests\Modules\Feedback\Support;

use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Catalog\Application\Command\ChooseInStore\ChooseInStore;
use Modules\Catalog\Application\Command\ChooseInStore\ChooseInStoreHandler;
use Modules\Feedback\Application\Command\WriteReview\WriteReview;
use Modules\Feedback\Application\Command\WriteReview\WriteReviewHandler;
use Modules\Sales\Public\Contracts\SalesApi;
use Modules\Sales\Public\Dto\DeliveredPurchaseDto;
use RuntimeException;
use Tests\Modules\Access\Support\AccessFixtures as Fx;
use Tests\Modules\Catalog\Support\CatalogProducts as Px;
use Throwable;

/** Feedback's tests: a product a store sells, a purchase Sales would answer, a review written. */
final class FeedbackFixtures
{
    /** Sales faked for this test (Sales is built after Feedback). */
    public static function sales(): FakeSales
    {
        if (! app()->bound(FakeSales::class)) {
            $sales = new FakeSales;
            app()->instance(FakeSales::class, $sales);
            app()->instance(SalesApi::class, $sales);
        }

        return app(FakeSales::class);
    }

    /**
     * A ready product chosen in these stores, with its variants.
     *
     * @param  list<string>  $storeCodes
     * @param  list<string>  $sizes
     * @return array{product: string, variants: list<string>}
     */
    public static function sold(array $storeCodes = ['sa'], array $sizes = ['60 cm', '80 cm']): array
    {
        $ready = Px::ready($sizes);

        foreach ($storeCodes as $code) {
            Fx::asSystem(fn () => app(ChooseInStoreHandler::class)->handle(new ChooseInStore(Fx::storeId($code), $ready['product'], true)));
        }

        return ['product' => $ready['product'], 'variants' => $ready['variants']];
    }

    public static function delivered(string $customerId, string $productId, string $variantId, bool $returned = false): void
    {
        self::sales()->deliver($customerId, $productId, new DeliveredPurchaseDto($variantId, CarbonImmutable::now()->subDays(3), $returned));
    }

    /** The customer writes a review in the store, as the shop sends it. */
    public static function write(string $customerId, string $productId, int $rating = 5, string $body = 'Solid and quiet.', string $storeCode = 'sa'): string
    {
        Fx::actAsCustomer($customerId);

        return app(WriteReviewHandler::class)->handle(new WriteReview(Fx::storeId($storeCode), $productId, $rating, $body));
    }

    /**
     * A review written straight into its table, for the tables' own tests.
     *
     * @param  array<string, mixed>  $with
     */
    public static function reviewRow(string $productId, string $variantId, string $customerId, array $with = [], string $storeCode = 'sa'): string
    {
        $id = strtolower((string) Str::ulid());
        DB::table('feedback.reviews')->insert([
            'id' => $id, 'product_id' => $productId, 'variant_id' => $variantId, 'customer_id' => $customerId,
            'store_id' => Fx::storeId($storeCode), 'rating' => 4, 'body' => 'Good', 'locale' => 'en', 'status' => 'PENDING',
            'mark' => 'VERIFIED_PURCHASE', 'created_at' => CarbonImmutable::now(), ...$with,
        ]);

        return $id;
    }

    /**
     * @template T of Throwable
     *
     * @param  class-string<T>  $class
     * @return T
     */
    public static function thrown(Closure $call, string $class): Throwable
    {
        try {
            $call();
        } catch (Throwable $thrown) {
            if ($thrown instanceof $class) {
                return $thrown;
            }

            throw $thrown;
        }

        throw new RuntimeException("Expected {$class}, nothing was thrown.");
    }
}
