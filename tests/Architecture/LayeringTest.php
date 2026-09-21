<?php

declare(strict_types=1);

/*
| Handoff §4.3 and §19. Module-to-module imports are enforced by Deptrac
| (deptrac.yaml); these tests cover the rules inside a module.
*/

require_once __DIR__.'/helpers.php';

arch('the shared domain is framework-free', function () {
    expect('Shared\Domain')->not->toUse(['Illuminate', 'Laravel', ...LARAVEL_HELPERS]);
});

it('finds modules with code to check', function () {
    // Named one by one: a renamed or moved module must fail here, not quietly stop
    // generating every check below it (review of step 7).
    expect(modulesWithCode())->toContain('Platform')->toContain('Access');
});

foreach (modulesWithCode() as $module) {
    arch("{$module}: Domain is framework-free", function () use ($module) {
        expect("Modules\\{$module}\\Domain")->not->toUse(['Illuminate', 'Laravel', ...LARAVEL_HELPERS]);
    });

    arch("{$module}: Public never references Eloquent", function () use ($module) {
        expect("Modules\\{$module}\\Public")->not->toUse('Illuminate\Database\Eloquent');
    });

    arch("{$module}: Domain repositories are interfaces only", function () use ($module) {
        expect("Modules\\{$module}\\Domain\\Repository")->toBeInterfaces();
    });
}
