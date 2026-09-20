<?php

declare(strict_types=1);

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\Http;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // No test reaches the network. A real call would be slow, would decide the test by what a
        // service answers that day, and would tell a stranger which passwords we hash (step 7).
        Http::preventStrayRequests();
    }
}
