<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Modules\B2B\Domain\Model\Application;
use Modules\B2B\Domain\ValueObject\ApplicationReference;
use Modules\B2B\Domain\ValueObject\ApplicationState;

/*
| An application's number (b2b.md §1.2, amendment 14(g)): TW-CO-, the year as two digits, and that
| year's count from 0001.
*/

it('writes the year as two digits and the count as at least four', function (int $year, int $number, string $written) {
    expect(ApplicationReference::of($year, $number)->value)->toBe($written);
})->with([
    [2026, 1, 'TW-CO-26-0001'],
    [2026, 42, 'TW-CO-26-0042'],
    [2027, 9999, 'TW-CO-27-9999'],
    'past 9999 it grows' => [2026, 12345, 'TW-CO-26-12345'],
    [2000, 7, 'TW-CO-00-0007'],
    [2099, 1, 'TW-CO-99-0001'],
]);

it('gives no number outside the century two digits can name, nor one below 1', function (int $year, int $number) {
    expect(fn () => ApplicationReference::of($year, $number))->toThrow(LogicException::class);
})->with([[1999, 1], [2100, 1], [2026, 0], [2026, -3]]);

it('reads back only its own shape', function (string $value, bool $accepted) {
    $read = fn () => ApplicationReference::reconstitute($value);

    $accepted ? expect($read()->value)->toBe($value) : expect($read)->toThrow(LogicException::class);
})->with([
    ['TW-CO-26-0001', true],
    ['TW-CO-26-12345', true],
    ['TW-CO-26-0000', false],
    ['TW-CO-2026-0001', false],
    ['TW-CO-26-001', false],
    ['tw-co-26-0001', false],
    ['TW-CO-26-0001 ', false],
    ["TW-CO-26-0001\n", false],
]);

it('is given to an application when it is sent, and never to a draft read back', function () {
    $sent = Application::reconstitute(
        '01j8z3k4m5n6p7q8r9s0t1v2w3', '01j8z3k4m5n6p7q8r9s0t1v2w4', '01j8z3k4m5n6p7q8r9s0t1v2w5', ApplicationState::Submitted,
        null, null, null, null, null, null, [], CarbonImmutable::now(), ApplicationReference::of(2026, 3), null, null, null, [], [], [], '01j8z3k4m5n6p7q8r9s0t1v2s5',
    );

    expect($sent->reference()?->value)->toBe('TW-CO-26-0003')
        ->and(fn () => Application::reconstitute(
            '01j8z3k4m5n6p7q8r9s0t1v2w3', '01j8z3k4m5n6p7q8r9s0t1v2w4', null, ApplicationState::Draft,
            null, null, null, null, null, null, [], null, ApplicationReference::of(2026, 3), null, null, null, [], [], [], '01j8z3k4m5n6p7q8r9s0t1v2s5',
        ))->toThrow(LogicException::class)
        ->and(fn () => Application::reconstitute(
            '01j8z3k4m5n6p7q8r9s0t1v2w3', '01j8z3k4m5n6p7q8r9s0t1v2w4', '01j8z3k4m5n6p7q8r9s0t1v2w5', ApplicationState::Rejected,
            null, null, null, null, null, null, [], CarbonImmutable::now(), null, null, null, null, [], [], [], '01j8z3k4m5n6p7q8r9s0t1v2s5',
        ))->toThrow(LogicException::class)
        ->and(Application::draft('01j8z3k4m5n6p7q8r9s0t1v2w6', '01j8z3k4m5n6p7q8r9s0t1v2w4', null, '01j8z3k4m5n6p7q8r9s0t1v2s5')->reference())->toBeNull();
});
