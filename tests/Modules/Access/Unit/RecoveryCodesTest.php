<?php

declare(strict_types=1);

use Modules\Access\Application\Security\RecoveryCodes;

/*
| Recovery codes (access.md amendment 91, decision 4): ten, all different, in a shape a person can
| copy off paper, and the same code however it is typed.
*/

it('makes ten different codes of two groups of five, without look-alike characters', function () {
    $codes = RecoveryCodes::generate();

    expect($codes)->toHaveCount(10)
        ->and(array_unique($codes))->toHaveCount(10);

    foreach ($codes as $code) {
        expect($code)->toMatch('/^[acdefghjkmnpqrtuvwxyz2345679]{5}-[acdefghjkmnpqrtuvwxyz2345679]{5}$/');
    }
});

it('reads a code typed in capitals, with spaces or without its dash, as the same code', function () {
    expect(RecoveryCodes::normalize('ABCDE-FGHJK'))->toBe('abcdefghjk')
        ->and(RecoveryCodes::normalize(' abcde fghjk '))->toBe('abcdefghjk')
        ->and(RecoveryCodes::normalize('abcdefghjk'))->toBe('abcdefghjk');
});
