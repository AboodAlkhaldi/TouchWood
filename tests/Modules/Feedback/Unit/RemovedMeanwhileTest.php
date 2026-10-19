<?php

declare(strict_types=1);

use Illuminate\Database\QueryException;
use Modules\Feedback\Application\RemovedMeanwhile;
use Modules\Feedback\Domain\Exception\InvalidFeedbackAttribute;

/*
| A product Catalog deleted between Feedback's check and its write (feedback.md §1.1, §1.3): named as
| the check would have; any other database error left as it is.
*/

function feedbackFailedWith(string $sqlState, string $message): QueryException
{
    $driver = new PDOException($message);
    (new ReflectionProperty(Exception::class, 'code'))->setValue($driver, $sqlState);

    return new QueryException('pgsql', 'insert into feedback', [], $driver);
}

it('names a product or variant key broken meanwhile as the product', function (string $constraint) {
    $named = RemovedMeanwhile::of(feedbackFailedWith('23503', "violates foreign key constraint \"{$constraint}\""));

    expect($named instanceof InvalidFeedbackAttribute ? $named->attribute : null)->toBe('product');
})->with(['reviews_product', 'reviews_variant', 'questions_product']);

it('leaves any other database error as it is', function (string $sqlState, string $message) {
    $failed = feedbackFailedWith($sqlState, $message);

    expect(RemovedMeanwhile::of($failed))->toBe($failed);
})->with([
    'the customer\'s key' => ['23503', 'violates foreign key constraint "questions_customer"'],
    'a longer name' => ['23503', 'violates foreign key constraint "questions_productx"'],
    'not a key' => ['23505', 'duplicate key value violates unique constraint "reviews_product"'],
]);
