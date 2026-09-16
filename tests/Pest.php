<?php

use Tests\TestCase;

/*
| Unit tests run without the framework. Integration and Feature tests boot the
| application against PostgreSQL (see phpunit.xml).
*/

pest()->extend(TestCase::class)->in('Modules/*/Integration', 'Modules/*/Feature');
