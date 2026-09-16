<?php

use Modules\Platform\Public\Dto\SettingValueDto;

function settingHolding(mixed $value): SettingValueDto
{
    return new SettingValueDto('testing.otp.max_resends', null, false, $value);
}

it('reads a value as the type it holds', function () {
    expect(settingHolding(5)->int())->toBe(5)
        ->and(settingHolding(true)->bool())->toBeTrue()
        ->and(settingHolding('sms')->string())->toBe('sms')
        ->and(settingHolding(['a', 'b'])->list())->toBe(['a', 'b']);
});

it('refuses to read a value as a type it does not hold, instead of coercing it', function (mixed $value, string $reader) {
    settingHolding($value)->{$reader}();
})->throws(LogicException::class, 'testing.otp.max_resends')->with([
    'numeric text as an integer' => ['5', 'int'],
    'one as a boolean' => [1, 'bool'],
    'an integer as text' => [5, 'string'],
    'a map as a list' => [['a' => 1], 'list'],
    'nothing as an integer' => [null, 'int'],
]);
