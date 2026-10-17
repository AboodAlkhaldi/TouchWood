<?php

declare(strict_types=1);

use App\Http\FormErrors;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Lang;
use Modules\Promotions\Domain\Exception\CodeTaken;
use Modules\Promotions\Domain\Exception\CouponChanged;
use Modules\Promotions\Domain\Exception\CouponInUse;
use Modules\Promotions\Domain\Exception\CouponNotFound;
use Modules\Promotions\Domain\Exception\CouponRefused;
use Modules\Promotions\Domain\Exception\GiftLevelClash;
use Modules\Promotions\Domain\Exception\GiftLevelNotFound;
use Modules\Promotions\Domain\Exception\InvalidPromotionAttribute;
use Modules\Promotions\Domain\Exception\PromotionsError;
use Modules\Promotions\Domain\Exception\SegmentNotFound;
use Modules\Promotions\Public\Enums\CouponRefusal;
use Shared\Domain\Error\ErrorCategory;

/*
| What a person reads when Promotions refuses something (promotions.md §7): each error in their
| language, through the same FormErrors every screen uses, never the English written into the
| exception for the log — and each with the type and the status the spec gives it.
*/

/**
 * Every error of §7 built so far: its exact type and the status the spec gives it.
 *
 * @return array<string, array{0: PromotionsError, 1: string, 2: ErrorCategory}>
 */
function promotionsErrors(): array
{
    return [
        'coupon refused' => [CouponRefused::because(CouponRefusal::Ended), 'promotions.coupon_refused', ErrorCategory::Invalid],
        'coupon changed' => [new CouponChanged, 'promotions.coupon_changed', ErrorCategory::Conflict],
        'code taken' => [new CodeTaken('SUMMER10'), 'promotions.code_taken', ErrorCategory::Conflict],
        'coupon in use' => [new CouponInUse, 'promotions.coupon_in_use', ErrorCategory::Conflict],
        'gift level clash' => [new GiftLevelClash, 'promotions.gift_level_clash', ErrorCategory::Conflict],
        'coupon not found' => [new CouponNotFound, 'promotions.coupon_not_found', ErrorCategory::NotFound],
        'segment not found' => [new SegmentNotFound, 'promotions.segment_not_found', ErrorCategory::NotFound],
        'gift level not found' => [new GiftLevelNotFound, 'promotions.gift_level_not_found', ErrorCategory::NotFound],
        'invalid attribute' => [new InvalidPromotionAttribute('code', 'too short'), 'promotions.invalid_promotion_attribute', ErrorCategory::Invalid],
    ];
}

/**
 * The concrete error classes in the module's folder, whatever a list above says.
 *
 * @return list<class-string<PromotionsError>>
 */
function promotionsErrorClassesInFolder(): array
{
    $classes = [];

    foreach (glob(dirname(__DIR__, 4).'/src/Modules/Promotions/Domain/Exception/*.php') ?: [] as $file) {
        /** @var class-string<PromotionsError> $class */
        $class = 'Modules\\Promotions\\Domain\\Exception\\'.basename($file, '.php');

        if (! (new ReflectionClass($class))->isAbstract()) {
            $classes[] = $class;
        }
    }

    sort($classes);

    return $classes;
}

// A guard over files must find something, or it passes over nothing; and an error added later must
// join the list above, or none of these checks reaches it.
it('checks every error in the module\'s folder, and the folder holds them all', function () {
    $listed = array_map(static fn (array $row): string => $row[0]::class, array_values(promotionsErrors()));
    sort($listed);

    expect(promotionsErrorClassesInFolder())->toHaveCount(9)
        ->and(promotionsErrorClassesInFolder())->toBe($listed);
});

it('gives each error its own type, its status, and a title and a message in both languages', function (PromotionsError $error, string $type, ErrorCategory $category) {
    expect($error->type())->toBe($type)
        ->and($error->category())->toBe($category);

    $name = substr($type, strlen('promotions.'));

    foreach (['ar', 'en'] as $locale) {
        foreach (['title', 'detail'] as $part) {
            // hasForLocale: trans() would fall back to English for a missing Arabic line.
            expect(Lang::hasForLocale("promotions::errors.{$name}.{$part}", $locale))->toBeTrue("promotions::errors.{$name}.{$part} has no {$locale} text");
        }
    }
})->with(promotionsErrors());

it('gives no two errors one type', function () {
    $types = array_map(static fn (array $row): string => $row[0]->type(), array_values(promotionsErrors()));

    expect($types)->toBe(array_values(array_unique($types)));
});

it('names the taken code in the message, in both languages', function () {
    App::setLocale('en');
    expect(FormErrors::message(new CodeTaken('SUMMER10')))->toBe('Another coupon in this store already has the code SUMMER10. Choose another code.');

    App::setLocale('ar');
    expect(FormErrors::message(new CodeTaken('SUMMER10')))->toBe('الرمز SUMMER10 مستخدم لكوبون آخر في هذا المتجر. اختر رمزًا آخر.')
        ->and((new CodeTaken('SUMMER10'))->context())->toBe(['code' => 'SUMMER10']);
});

it('names a refused value as a word, never as its key', function () {
    App::setLocale('en');
    expect(FormErrors::message(new InvalidPromotionAttribute('percent', 'above 100')))->toBe('The percentage isn\'t valid. Check it and try again.');

    App::setLocale('ar');
    expect(FormErrors::message(new InvalidPromotionAttribute('percent', 'above 100')))->toBe('قيمة النسبة غير صالحة. تحقّق منها وحاول مجددًا.');
});

it('has a word in both languages for every value a refusal can name', function (string $field) {
    foreach (['ar', 'en'] as $locale) {
        expect(Lang::hasForLocale("promotions::errors.fields.{$field}", $locale))->toBeTrue("no {$locale} word for {$field}");
    }
})->with(['code', 'percent', 'amount', 'dates', 'store', 'permission', 'customer', 'currency', 'line', 'order', 'order_number', 'coupon', 'edit']);

it('carries the refusal\'s reason for the caller, which reads errors by their type', function () {
    expect(CouponRefused::because(CouponRefusal::UsedUp)->context())->toBe(['reason' => 'USED_UP'])
        ->and(CouponRefused::anotherCoupon()->context())->toBe(['reason' => 'ANOTHER_COUPON'])
        ->and(CouponRefused::anotherCoupon()->reason)->toBeNull();
});

it('names the dates as one range, so the sentence reads right', function () {
    App::setLocale('en');
    expect(FormErrors::message(new InvalidPromotionAttribute('dates', 'end before start')))->toBe('The date range isn\'t valid. Check it and try again.');
});

it('never shows the English written for the log', function (PromotionsError $error) {
    App::setLocale('ar');

    expect(FormErrors::message($error))->not->toBe($error->getMessage());
})->with(promotionsErrors());
