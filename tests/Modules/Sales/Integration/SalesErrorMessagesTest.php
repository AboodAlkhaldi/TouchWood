<?php

declare(strict_types=1);

use App\Http\FormErrors;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Lang;
use Modules\Sales\Domain\Exception\AddressNotUsable;
use Modules\Sales\Domain\Exception\InvalidOrderChange;
use Modules\Sales\Domain\Exception\InvalidSalesAttribute;
use Modules\Sales\Domain\Exception\MayNotOrder;
use Modules\Sales\Domain\Exception\NotCancellable;
use Modules\Sales\Domain\Exception\NotEditable;
use Modules\Sales\Domain\Exception\NotForYourAccount;
use Modules\Sales\Domain\Exception\NotOrderable;
use Modules\Sales\Domain\Exception\NotShippable;
use Modules\Sales\Domain\Exception\OrderNotFound;
use Modules\Sales\Domain\Exception\QuoteChanged;
use Modules\Sales\Domain\Exception\QuoteExpired;
use Modules\Sales\Domain\Exception\QuoteNotFound;
use Modules\Sales\Domain\Exception\RefundTooLarge;
use Modules\Sales\Domain\Exception\ReturnNotAllowed;
use Modules\Sales\Domain\Exception\ReturnNotFound;
use Modules\Sales\Domain\Exception\SalesError;
use Modules\Sales\Domain\Exception\SignInToUseCoupon;
use Modules\Sales\Domain\Exception\TooManyFiles;
use Modules\Sales\Domain\Exception\WayToPayNotOffered;
use Shared\Domain\Error\ErrorCategory;

/*
| What a person reads when Sales refuses something (sales.md §7): each error in their language,
| through the same FormErrors every screen uses, never the English written for the log — and each
| with the type and the status the spec gives it.
*/

/**
 * @return array<string, array{0: SalesError, 1: string, 2: ErrorCategory}>
 */
function salesErrors(): array
{
    return [
        'MayNotOrder' => [new MayNotOrder, 'sales.may_not_order', ErrorCategory::Forbidden],
        'NotForYourAccount' => [new NotForYourAccount, 'sales.not_for_your_account', ErrorCategory::Forbidden],
        'NotOrderable' => [new NotOrderable, 'sales.not_orderable', ErrorCategory::Invalid],
        'AddressNotUsable' => [new AddressNotUsable, 'sales.address_not_usable', ErrorCategory::Invalid],
        'WayToPayNotOffered' => [new WayToPayNotOffered, 'sales.way_to_pay_not_offered', ErrorCategory::Invalid],
        'SignInToUseCoupon' => [new SignInToUseCoupon, 'sales.sign_in_to_use_coupon', ErrorCategory::Invalid],
        'QuoteNotFound' => [new QuoteNotFound, 'sales.quote_not_found', ErrorCategory::NotFound],
        'QuoteExpired' => [new QuoteExpired, 'sales.quote_expired', ErrorCategory::Conflict],
        'QuoteChanged' => [new QuoteChanged, 'sales.quote_changed', ErrorCategory::Conflict],
        'NotCancellable' => [new NotCancellable, 'sales.not_cancellable', ErrorCategory::Conflict],
        'NotEditable' => [new NotEditable, 'sales.not_editable', ErrorCategory::Conflict],
        'NotShippable' => [new NotShippable, 'sales.not_shippable', ErrorCategory::Conflict],
        'ReturnNotAllowed' => [new ReturnNotAllowed, 'sales.return_not_allowed', ErrorCategory::Conflict],
        'TooManyFiles' => [new TooManyFiles, 'sales.too_many_files', ErrorCategory::Conflict],
        'RefundTooLarge' => [new RefundTooLarge, 'sales.refund_too_large', ErrorCategory::Conflict],
        'InvalidOrderChange' => [new InvalidOrderChange, 'sales.invalid_order_change', ErrorCategory::Conflict],
        'OrderNotFound' => [new OrderNotFound, 'sales.order_not_found', ErrorCategory::NotFound],
        'ReturnNotFound' => [new ReturnNotFound, 'sales.return_not_found', ErrorCategory::NotFound],
        'InvalidSalesAttribute' => [new InvalidSalesAttribute('quantity', 'above the maximum'), 'sales.invalid_sales_attribute', ErrorCategory::Invalid],
    ];
}

/**
 * @return list<class-string<SalesError>>
 */
function salesErrorClassesInFolder(): array
{
    $classes = [];

    foreach (glob(dirname(__DIR__, 4).'/src/Modules/Sales/Domain/Exception/*.php') ?: [] as $file) {
        /** @var class-string<SalesError> $class */
        $class = 'Modules\\Sales\\Domain\\Exception\\'.basename($file, '.php');

        if (! (new ReflectionClass($class))->isAbstract()) {
            $classes[] = $class;
        }
    }

    sort($classes);

    return $classes;
}

it('checks every error in the module\'s folder, and the folder holds them all', function () {
    $listed = array_map(static fn (array $row): string => $row[0]::class, array_values(salesErrors()));
    sort($listed);

    expect(salesErrorClassesInFolder())->toHaveCount(19)
        ->and(salesErrorClassesInFolder())->toBe($listed);
});

it('gives each error its own type, its status, and a title and a message in both languages', function (SalesError $error, string $type, ErrorCategory $category) {
    expect($error->type())->toBe($type)
        ->and($error->category())->toBe($category);

    $name = substr($type, strlen('sales.'));

    foreach (['ar', 'en'] as $locale) {
        foreach (['title', 'detail'] as $part) {
            expect(Lang::hasForLocale("sales::errors.{$name}.{$part}", $locale))->toBeTrue("sales::errors.{$name}.{$part} has no {$locale} text");
        }
    }
})->with(salesErrors());

it('gives no two errors one type', function () {
    $types = array_map(static fn (array $row): string => $row[0]->type(), array_values(salesErrors()));

    expect($types)->toBe(array_values(array_unique($types)));
});

it('names a refused value as a word, never as its key', function () {
    App::setLocale('en');
    expect(FormErrors::message(new InvalidSalesAttribute('way_to_pay', 'not offered')))->toBe('The way to pay isn\'t valid. Check it and try again.');
});

it('has a word in both languages for every value a refusal can name', function (string $field) {
    foreach (['ar', 'en'] as $locale) {
        expect(Lang::hasForLocale("sales::errors.fields.{$field}", $locale))->toBeTrue("no {$locale} word for {$field}");
    }
})->with(['store', 'quantity', 'variant', 'sale_mode', 'coupon', 'address', 'way_to_pay', 'reason', 'amount', 'locale']);

it('never shows the English written for the log', function (SalesError $error) {
    App::setLocale('ar');

    expect(FormErrors::message($error))->not->toBe($error->getMessage());
})->with(salesErrors());
