<?php

declare(strict_types=1);

/*
| One DTO style at module boundaries (owner's decision, 2026-09-18): plain readonly PHP classes.
| spatie/laravel-data stays available for the presentation layer, never for Public/Dto.
*/

require_once __DIR__.'/helpers.php';

foreach (modulesWithCode() as $module) {
    arch("{$module}: DTOs at the module boundary are plain classes, not laravel-data objects", function () use ($module) {
        expect("Modules\\{$module}\\Public\\Dto")->not->toUse('Spatie\LaravelData');
    });
}
