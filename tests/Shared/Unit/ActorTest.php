<?php

declare(strict_types=1);

use Shared\Application\Actor;
use Shared\Application\ActorType;

const STAFF_ID = '01j8z3k4m5n6p7q8r9s0t1v2w3';

it('has no id when the system acts', function () {
    expect(Actor::system()->type)->toBe(ActorType::System)
        ->and(Actor::system()->id)->toBeNull()
        ->and(Actor::system()->requestedBy)->toBeNull();
});

it('identifies every other kind of actor by a ULID', function (string $factory, ActorType $type) {
    $actor = Actor::{$factory}('01J8Z3K4M5N6P7Q8R9S0T1V2W3');

    expect($actor->type)->toBe($type)
        ->and($actor->id)->toBe(STAFF_ID);
})->with([
    'staff' => ['staff', ActorType::Staff],
    'customer' => ['customer', ActorType::Customer],
    'guest' => ['guest', ActorType::Guest],
    'integration' => ['integration', ActorType::Integration],
]);

it('refuses an actor whose id is not a ULID', function (string $factory, string $id) {
    Actor::{$factory}($id);
})->throws(InvalidArgumentException::class)->with([
    'empty staff id' => ['staff', ''],
    'a name instead of an id' => ['integration', 'odoo'],
    'too short' => ['guest', '01j8z3k4m5n6'],
    'letters a ULID never uses' => ['customer', '01j8z3k4m5n6p7q8r9s0t1v2wu'],
    'too large for 128 bits' => ['staff', '8ZZZZZZZZZZZZZZZZZZZZZZZZZ'],
]);

it('accepts the largest ULID', function () {
    expect(Actor::staff('7ZZZZZZZZZZZZZZZZZZZZZZZZZ')->id)->toBe('7zzzzzzzzzzzzzzzzzzzzzzzzz');
});

it('records whose action started the system work', function () {
    $system = Actor::system(Actor::staff(STAFF_ID));

    expect($system->type)->toBe(ActorType::System)
        ->and($system->requestedBy?->type)->toBe(ActorType::Staff)
        ->and($system->requestedBy?->id)->toBe(STAFF_ID);
});

it('keeps the original requester when system work starts more system work', function () {
    $first = Actor::system(Actor::staff(STAFF_ID));

    expect(Actor::system($first)->requestedBy?->id)->toBe(STAFF_ID)
        ->and(Actor::system(Actor::system())->requestedBy)->toBeNull();
});

it('rebuilds an actor stored as its type and id', function (ActorType $type) {
    $actor = Actor::of($type, $type === ActorType::System ? null : STAFF_ID);

    expect($actor->type)->toBe($type)
        ->and($actor->id)->toBe($type === ActorType::System ? null : STAFF_ID);
})->with(ActorType::cases());
