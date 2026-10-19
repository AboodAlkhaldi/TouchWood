<?php

declare(strict_types=1);

use Illuminate\Database\QueryException;
use Modules\Promotions\Application\Coupon\RuleTargets;
use Modules\Promotions\Domain\Exception\InvalidPromotionAttribute;

/*
| A coupon list's entry Catalog deleted between the check and the write (promotions.md §1.2) is named
| as the check would have named it; any other database error stays what it is.
*/

function failedWith(string $sqlState, string $message): QueryException
{
    $driver = new PDOException($message);
    (new ReflectionProperty(Exception::class, 'code'))->setValue($driver, $sqlState);

    return new QueryException('pgsql', 'insert into promotions.coupon_rules', [], $driver);
}

it('names a rule\'s Catalog key broken meanwhile as the rules, for a product, category or brand', function (string $constraint) {
    $named = RuleTargets::removedMeanwhile(failedWith('23503', "insert or update violates foreign key constraint \"{$constraint}\""));

    expect($named)->toBeInstanceOf(InvalidPromotionAttribute::class)
        ->and($named instanceof InvalidPromotionAttribute ? $named->attribute : null)->toBe('rules');
})->with(['coupon_rules_product', 'coupon_rules_category', 'coupon_rules_brand']);

it('leaves any other database error as it is', function (string $sqlState, string $message) {
    $failed = failedWith($sqlState, $message);

    expect(RuleTargets::removedMeanwhile($failed))->toBe($failed);
})->with([
    'another key' => ['23503', 'violates foreign key constraint "coupons_store"'],
    'the coupon\'s own key' => ['23503', 'violates foreign key constraint "coupon_rules_coupon"'],
    'not a key at all' => ['23505', 'duplicate key value violates unique constraint "coupon_rules_brand"'],
]);
