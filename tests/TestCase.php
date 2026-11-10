<?php

declare(strict_types=1);

namespace Tests;

use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Modules\Sync\Application\Connections\HostAddresses;
use Tests\Modules\Sync\Support\FakeHostAddresses;

abstract class TestCase extends BaseTestCase
{
    /**
     * Every test runs at this moment, so none can fail because a calendar date passed (two gift tests did, on the
     * day their fixed end date went by: 2026-11-02). A test about time sets its own clock. The framework clears
     * the clock when a test ends.
     */
    public const string FROZEN_AT = '2026-11-02T12:00:00Z';

    protected function setUp(): void
    {
        // Before the framework boots: what the migrations and seeders date belongs to the same moment.
        if (! str_starts_with(static::class, 'P\\Tests\\Browser\\')) {
            self::freezeClock();
        }

        parent::setUp();

        // No test reaches the network. A real call would be slow, would decide the test by what a
        // service answers that day, and would tell a stranger which passwords we hash (step 7).
        Http::preventStrayRequests();

        // Nor DNS: Sync's address rule resolves a provider's host (sync.md §2.4); a test names what it resolves to.
        $this->app->instance(HostAddresses::class, new FakeHostAddresses);
    }

    /**
     * Not for browser tests: a real browser judges a cookie's expiry by today's date, so a page served from a
     * frozen clock would lose its session once the moment is far enough behind.
     */
    private static function freezeClock(): void
    {
        $moment = CarbonImmutable::parse(self::FROZEN_AT);
        CarbonImmutable::setTestNow($moment);
        Carbon::setTestNow($moment);
    }
}
