<?php

/*
| Handoff §4.3 and §19. Module-to-module imports are enforced by Deptrac
| (deptrac.yaml); these tests cover the rules inside a module.
*/

$modules = array_map('basename', glob(__DIR__.'/../../src/Modules/*', GLOB_ONLYDIR) ?: []);

arch('the shared domain is framework-free', function () {
    expect('Shared\Domain')->not->toUse(['Illuminate', 'Laravel']);
});

foreach ($modules as $module) {
    arch("{$module}: Domain is framework-free", function () use ($module) {
        expect("Modules\\{$module}\\Domain")->not->toUse(['Illuminate', 'Laravel']);
    });

    arch("{$module}: Public never references Eloquent", function () use ($module) {
        expect("Modules\\{$module}\\Public")->not->toUse('Illuminate\Database\Eloquent');
    });

    arch("{$module}: Domain repositories are interfaces only", function () use ($module) {
        expect("Modules\\{$module}\\Domain\\Repository")->toBeInterfaces();
    });
}
