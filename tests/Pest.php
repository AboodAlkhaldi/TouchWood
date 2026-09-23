<?php

declare(strict_types=1);

use Tests\TestCase;

/*
| Unit tests run without the framework. Integration, Feature and Browser tests boot the
| application against PostgreSQL (see phpunit.xml).
|
| Browser tests need it too: the plugin serves the real application to a real browser, and without
| the framework booted even reading a config value fails before the browser is ever opened.
*/

pest()->extend(TestCase::class)->in(
    'Shared/Integration',
    'Shared/Feature',
    'Modules/*/Integration',
    'Modules/*/Feature',
    'Browser',
);
