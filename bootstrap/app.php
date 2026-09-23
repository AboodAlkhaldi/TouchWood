<?php

declare(strict_types=1);

use App\Exceptions\QueryErrorLog;
use App\Http\Middleware\AssignCorrelationId;
use App\Http\Middleware\HandleInertiaRequests;
use App\Http\ProblemDetails;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->prepend(AssignCorrelationId::class);
        // Every page of both areas is an Inertia page (frontend.md 1.3). This decides the shell,
        // the asset version and the theme; what a module shares on top is the module's own.
        $middleware->web(append: [HandleInertiaRequests::class]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        ProblemDetails::register($exceptions);
        QueryErrorLog::register($exceptions);
    })->create();
