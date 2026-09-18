<?php

declare(strict_types=1);

use Modules\Platform\Infrastructure\HttpRequestState;
use Modules\Platform\Infrastructure\Queue\JobActorState;
use Shared\Application\Actor;

const STATE_STAFF_ID = '01j8z3k4m5n6p7q8r9s0t1v2w3';

describe('whether a request is being served', function () {
    it('is always true in a web server process, even after the response is sent', function () {
        $state = new HttpRequestState(webServer: true);

        expect($state->isHandling())->toBeTrue();
    });

    it('is true in the console only while TrackHttpRequest handles a request', function () {
        $state = new HttpRequestState(webServer: false);

        expect($state->isHandling())->toBeFalse();

        $state->enter();
        expect($state->isHandling())->toBeTrue();

        $state->leave();
        expect($state->isHandling())->toBeFalse();
    });

    it('never goes below zero when left more often than entered', function () {
        $state = new HttpRequestState(webServer: false);
        $state->leave();
        $state->enter();

        expect($state->isHandling())->toBeTrue();
    });
});

describe('the actor of the running job', function () {
    it('is the innermost job\'s, and the outer one comes back when it ends', function () {
        $state = new JobActorState;
        $state->enter(1, Actor::system(Actor::staff(STATE_STAFF_ID)));
        $state->enter(2, Actor::system());

        expect($state->current()?->requestedBy)->toBeNull();

        $state->leave(2);
        expect($state->current()?->requestedBy?->id)->toBe(STATE_STAFF_ID);

        $state->leave(1);
        expect($state->current())->toBeNull();
    });

    it('ignores leaving a job that never entered, or leaving twice', function () {
        $state = new JobActorState;
        $state->enter(1, Actor::system(Actor::staff(STATE_STAFF_ID)));

        $state->leave(99);
        $state->leave(1);
        $state->leave(1);

        expect($state->current())->toBeNull();

        $state->enter(1, Actor::system());
        $state->leave(99);
        expect($state->current())->not->toBeNull();
    });
});
