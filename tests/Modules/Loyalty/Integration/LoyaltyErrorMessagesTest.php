<?php

declare(strict_types=1);

use App\Http\FormErrors;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Lang;
use Modules\Loyalty\Domain\Exception\DeductionTooLarge;
use Modules\Loyalty\Domain\Exception\InvalidPointsAttribute;
use Modules\Loyalty\Domain\Exception\LoyaltyError;
use Modules\Loyalty\Domain\Exception\OrderNotSettleable;
use Modules\Loyalty\Domain\Exception\PointsAccountNotFound;
use Modules\Loyalty\Domain\Exception\PointsProgrammeOff;
use Modules\Loyalty\Domain\Exception\RedemptionChanged;
use Shared\Domain\Error\ErrorCategory;

/*
| What a person reads when Loyalty refuses something (loyalty.md §7): each error in their language,
| through the same FormErrors every screen uses, never the English written into the exception for
| the log — and each with the type and the status the spec gives it.
*/

/**
 * Every error of §7 built so far: its exact type and the status the spec gives it.
 *
 * @return array<string, array{0: LoyaltyError, 1: string, 2: ErrorCategory}>
 */
function loyaltyErrors(): array
{
    return [
        'programme off' => [new PointsProgrammeOff, 'loyalty.points_programme_off', ErrorCategory::Conflict],
        'redemption changed' => [new RedemptionChanged, 'loyalty.redemption_changed', ErrorCategory::Conflict],
        'deduction too large' => [new DeductionTooLarge(120), 'loyalty.deduction_too_large', ErrorCategory::Conflict],
        'account not found' => [new PointsAccountNotFound, 'loyalty.points_account_not_found', ErrorCategory::NotFound],
        'order not settleable' => [new OrderNotSettleable('01j8z3k4m5n6p7q8r9s0t1v4o1', 'cancelled'), 'loyalty.order_not_settleable', ErrorCategory::Conflict],
        'invalid attribute' => [new InvalidPointsAttribute('reason', 'empty'), 'loyalty.invalid_points_attribute', ErrorCategory::Invalid],
    ];
}

/**
 * The concrete error classes in the module's folder, whatever a list above says.
 *
 * @return list<class-string<LoyaltyError>>
 */
function loyaltyErrorClassesInFolder(): array
{
    $classes = [];

    foreach (glob(dirname(__DIR__, 4).'/src/Modules/Loyalty/Domain/Exception/*.php') ?: [] as $file) {
        /** @var class-string<LoyaltyError> $class */
        $class = 'Modules\\Loyalty\\Domain\\Exception\\'.basename($file, '.php');

        if (! (new ReflectionClass($class))->isAbstract()) {
            $classes[] = $class;
        }
    }

    sort($classes);

    return $classes;
}

// A guard over files must find something, or it passes over nothing; and an error added later
// (RedemptionRefused, with step 3) must join the list above, or none of these checks reaches it.
it('checks every error in the module\'s folder, and the folder holds them all', function () {
    $listed = array_map(static fn (array $row): string => $row[0]::class, array_values(loyaltyErrors()));
    sort($listed);

    expect(loyaltyErrorClassesInFolder())->toHaveCount(6)
        ->and(loyaltyErrorClassesInFolder())->toBe($listed);
});

it('gives each error its own type, its status, and a title and a message in both languages', function (LoyaltyError $error, string $type, ErrorCategory $category) {
    expect($error->type())->toBe($type)
        ->and($error->category())->toBe($category);

    $name = substr($type, strlen('loyalty.'));

    foreach (['ar', 'en'] as $locale) {
        foreach (['title', 'detail'] as $part) {
            // hasForLocale: trans() would fall back to English for a missing Arabic line.
            expect(Lang::hasForLocale("loyalty::errors.{$name}.{$part}", $locale))->toBeTrue("loyalty::errors.{$name}.{$part} has no {$locale} text");
        }
    }
})->with(loyaltyErrors());

it('gives no two errors one type', function () {
    $types = array_map(static fn (array $row): string => $row[0]->type(), array_values(loyaltyErrors()));

    expect($types)->toBe(array_values(array_unique($types)));
});

it('tells the admin a deduction is too large without the customer\'s balance, in their language', function () {
    App::setLocale('en');
    expect(FormErrors::message(new DeductionTooLarge(120)))->toBe('The customer doesn\'t have that many points. Remove fewer.');

    App::setLocale('ar');
    expect(FormErrors::message(new DeductionTooLarge(120)))->toBe('لا يملك العميل هذا العدد من النقاط. اخصم عددًا أقل.')
        ->and((new DeductionTooLarge(120))->context())->toBe([]);
});

it('names a refused value as a word, never as its key', function () {
    App::setLocale('en');
    expect(FormErrors::message(new InvalidPointsAttribute('points', 'not a whole number')))->toBe('The number of points isn\'t valid. Check it and try again.');

    App::setLocale('ar');
    expect(FormErrors::message(new InvalidPointsAttribute('points', 'not a whole number')))->toBe('قيمة عدد النقاط غير صالحة. تحقّق منها وحاول مجددًا.');
});

it('has a word in both languages for every value a refusal can name', function (string $field) {
    foreach (['ar', 'en'] as $locale) {
        expect(Lang::hasForLocale("loyalty::errors.fields.{$field}", $locale))->toBeTrue("no {$locale} word for {$field}");
    }
})->with(['points', 'reason', 'returned_amounts', 'currency', 'store', 'permission']);

it('never shows the English written for the log', function (LoyaltyError $error) {
    App::setLocale('ar');

    expect(FormErrors::message($error))->not->toBe($error->getMessage());
})->with(loyaltyErrors());
