<?php

declare(strict_types=1);

/*
| One DTO style at module boundaries (owner's decision, 2026-09-18): plain final readonly PHP
| classes. spatie/laravel-data stays available for the presentation layer, never for Public/Dto.
| AuditChanges is the one exception: a builder, final but mutable.
*/

use Modules\Platform\Public\Dto\AuditChanges;

require_once __DIR__.'/helpers.php';

/**
 * Modules that have DTOs at their boundary.
 *
 * @return list<string>
 */
function modulesWithDtos(): array
{
    return array_values(array_filter(
        modulesWithCode(),
        fn (string $module): bool => glob(dirname(__DIR__, 2)."/src/Modules/{$module}/Public/Dto/*.php") !== [],
    ));
}

it('finds DTOs to check', function () {
    expect(modulesWithDtos())->toContain('Platform')->toContain('Access');
});

foreach (modulesWithDtos() as $module) {
    arch("{$module}: DTOs at the module boundary are plain classes, not laravel-data objects", function () use ($module) {
        expect("Modules\\{$module}\\Public\\Dto")->not->toUse('Spatie\LaravelData');
    });

    arch("{$module}: DTOs at the module boundary are final and readonly", function () use ($module) {
        expect("Modules\\{$module}\\Public\\Dto")->classes()->toBeFinal()->toBeReadonly()->ignoring(AuditChanges::class);
    });
}
