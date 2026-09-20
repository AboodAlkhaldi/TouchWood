<?php

declare(strict_types=1);

use Database\Seeders\PlatformSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Access\Application\Security\SignInLimits;
use Modules\Access\Domain\Exception\AccountLocked;

use function Pest\Laravel\seed;

uses(RefreshDatabase::class);

beforeEach(function () {
    seed(PlatformSeeder::class);
});

function signInLimits(): SignInLimits
{
    return app(SignInLimits::class);
}

it('counts an attempt before its password is checked: a sixth at once is refused (review of step 3b)', function () {
    // Five attempts under way, none of them answered yet.
    foreach (range(1, 5) as $attempt) {
        signInLimits()->begin('sara@example.test', '10.0.0.'.$attempt);
    }

    expect(fn () => signInLimits()->begin('sara@example.test', '10.0.0.9'))->toThrow(AccountLocked::class);
});

it('counts an address\'s attempts at once too, across accounts', function () {
    foreach (range(1, 10) as $attempt) {
        signInLimits()->begin("someone{$attempt}@example.test", '10.0.0.1');
    }

    expect(fn () => signInLimits()->begin('sara@example.test', '10.0.0.1'))->toThrow(AccountLocked::class);
});

it('gives an attempt back when the password is right, so an office signing in together is never locked', function () {
    foreach (range(1, 25) as $person) {
        signInLimits()->begin("staff{$person}@example.test", '10.0.0.1');
        signInLimits()->succeeded("staff{$person}@example.test", '10.0.0.1');
    }

    expect(fn () => signInLimits()->begin('sara@example.test', '10.0.0.1'))->not->toThrow(AccountLocked::class);
});

it('does not count a refused attempt, so the address is free once one under way turns out right', function () {
    foreach (range(1, 10) as $attempt) {
        signInLimits()->begin("someone{$attempt}@example.test", '10.0.0.1');
    }

    foreach (range(1, 3) as $refused) {
        expect(fn () => signInLimits()->begin("late{$refused}@example.test", '10.0.0.1'))->toThrow(AccountLocked::class);
    }

    signInLimits()->succeeded('someone1@example.test', '10.0.0.1');

    expect(fn () => signInLimits()->begin('sara@example.test', '10.0.0.1'))->not->toThrow(AccountLocked::class);
});
