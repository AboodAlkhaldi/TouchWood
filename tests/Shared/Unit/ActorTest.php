<?php

declare(strict_types=1);

use Shared\Application\Actor;
use Shared\Application\ActorType;

it('has no id when the system acts', function () {
    expect(Actor::system()->type)->toBe(ActorType::System)
        ->and(Actor::system()->id)->toBeNull();
});

it('identifies the staff member or customer acting', function () {
    $staff = Actor::staff('01j8z3k4m5n6p7q8r9s0t1v2w3');
    $customer = Actor::customer('01j8z3k4m5n6p7q8r9s0t1v2w4');

    expect($staff->type)->toBe(ActorType::Staff)
        ->and($staff->id)->toBe('01j8z3k4m5n6p7q8r9s0t1v2w3')
        ->and($customer->type)->toBe(ActorType::Customer)
        ->and($customer->id)->toBe('01j8z3k4m5n6p7q8r9s0t1v2w4');
});

it('refuses a staff member or customer without an id', function (string $factory) {
    Actor::{$factory}('');
})->throws(InvalidArgumentException::class)->with(['staff', 'customer']);
