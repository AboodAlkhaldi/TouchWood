<?php

declare(strict_types=1);

use Illuminate\Console\Scheduling\CallbackEvent;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Queue\ShouldQueue;

/*
| Owner's decision (2026-09-18): scheduled work is queued as a job, so anything it audits says JOB.
| $schedule->job(...) only — never command() or call(), which run in the console.
*/

it('schedules only queued jobs', function () {
    $events = app(Schedule::class)->events();

    expect($events)->not->toBeEmpty();

    foreach ($events as $event) {
        expect($event)->toBeInstanceOf(CallbackEvent::class, "\"{$event->getSummaryForDisplay()}\" is not a queued job")
            ->and(is_subclass_of((string) $event->description, ShouldQueue::class))->toBeTrue("\"{$event->description}\" is not a queued job");
    }
});
