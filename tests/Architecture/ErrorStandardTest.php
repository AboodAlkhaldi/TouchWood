<?php

declare(strict_types=1);

use Shared\Domain\Error\DomainError;

/*
| Platform spec §7: one global error standard, errors owned by modules. Domain and
| Application code pick an error category and never know about HTTP.
|
| Each layer gets its own arch() call: an expectation given several namespaces at once
| only checked the first of them.
*/

require_once __DIR__.'/helpers.php';

$http = ['Illuminate\Http', 'Symfony\Component\HttpFoundation', 'Symfony\Component\HttpKernel\Exception', ...LARAVEL_HTTP_HELPERS];

foreach (['Shared\Domain', 'Shared\Application'] as $layer) {
    arch("{$layer} never knows HTTP", function () use ($layer, $http) {
        expect($layer)->not->toUse($http);
    });
}

foreach (modulesWithCode() as $module) {
    arch("{$module}: every domain error extends DomainError", function () use ($module) {
        expect("Modules\\{$module}\\Domain\\Exception")->toExtend(DomainError::class);
    });

    foreach (['Domain', 'Application'] as $layer) {
        arch("{$module}: {$layer} never knows HTTP", function () use ($module, $layer, $http) {
            expect("Modules\\{$module}\\{$layer}")->not->toUse($http);
        });
    }
}
