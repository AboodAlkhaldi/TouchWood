<?php

use Shared\Domain\Error\DomainError;

/*
| Platform spec §7: one global error standard, errors owned by modules. Domain and
| Application code pick an error category and never know about HTTP.
|
| Each layer gets its own arch() call: an expectation given several namespaces at once
| only checked the first of them.
*/

$modules = array_map('basename', glob(__DIR__.'/../../src/Modules/*', GLOB_ONLYDIR) ?: []);
$httpNamespaces = ['Illuminate\Http', 'Symfony\Component\HttpFoundation', 'Symfony\Component\HttpKernel\Exception'];

foreach (['Shared\Domain', 'Shared\Application'] as $layer) {
    arch("{$layer} never knows HTTP", function () use ($layer, $httpNamespaces) {
        expect($layer)->not->toUse($httpNamespaces);
    });
}

foreach ($modules as $module) {
    arch("{$module}: every domain error extends DomainError", function () use ($module) {
        expect("Modules\\{$module}\\Domain\\Exception")->toExtend(DomainError::class);
    });

    foreach (['Domain', 'Application'] as $layer) {
        arch("{$module}: {$layer} never knows HTTP", function () use ($module, $layer, $httpNamespaces) {
            expect("Modules\\{$module}\\{$layer}")->not->toUse($httpNamespaces);
        });
    }
}
