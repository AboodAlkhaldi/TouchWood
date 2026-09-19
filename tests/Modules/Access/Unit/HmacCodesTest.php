<?php

declare(strict_types=1);

use Modules\Access\Infrastructure\Security\HmacCodes;

it('makes codes of exactly the set length, keeping leading zeros', function () {
    $codes = new HmacCodes('key');

    foreach (range(1, 200) as $try) {
        expect($codes->generate(6))->toMatch('/^\d{6}$/');
    }

    expect($codes->generate(4))->toMatch('/^\d{4}$/');
});

it('stores a keyed hash that only the right code, for the right person, matches', function () {
    $codes = new HmacCodes('key');
    $hash = $codes->hash('staff-1', '012345');

    expect($hash)->not->toContain('012345');
    expect($hash)->toMatch('/^[0-9a-f]{64}$/')
        ->and($codes->matches('staff-1', '012345', $hash))->toBeTrue()
        ->and($codes->matches('staff-1', ' 012345 ', $hash))->toBeTrue()
        ->and($codes->matches('staff-1', '012346', $hash))->toBeFalse()
        ->and($codes->matches('staff-2', '012345', $hash))->toBeFalse()
        ->and($codes->matches('staff-1', '12345', $hash))->toBeFalse();
});

it('depends on the application key, so a copy of the database cannot test codes', function () {
    $hash = (new HmacCodes('key-a'))->hash('staff-1', '012345');

    expect($hash)->not->toBe((new HmacCodes('key-b'))->hash('staff-1', '012345'));
    expect($hash)->not->toBe(hash('sha256', 'staff-1:012345'));
});
