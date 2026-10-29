<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Process;
use Modules\Access\Infrastructure\AccessServiceProvider;

/*
| A production server never starts on Laravel's `log` mailer (owner, 2026-09-19). The security messages
| themselves are Ops' since Ops bound its own sender (tests/Modules/Ops/Integration/SecurityMessagesTest).
*/

it('never starts in production without a real mailer, which would log every link (owner, 2026-09-19)', function (string $environment, string $mailer, bool $refused) {
    app()->detectEnvironment(fn (): string => $environment);
    config(['mail.default' => $mailer]);

    $check = fn () => AccessServiceProvider::requireRealMailer(app());

    $refused
        ? expect($check)->toThrow(InvalidArgumentException::class, 'MAIL_MAILER is "'.$mailer.'"')
        : expect($check)->not->toThrow(InvalidArgumentException::class);
})->with([
    'production, log' => ['production', 'log', true],
    'production, array' => ['production', 'array', true],
    'production, none' => ['production', '', true],
    'production, smtp' => ['production', 'smtp', false],
    'a developer\'s machine, log' => ['local', 'log', false],
]);

it('lets a checkout with no .env yet run: composer install discovers packages before one exists', function () {
    app()->detectEnvironment(fn (): string => 'production');
    config(['mail.default' => 'log', 'app.key' => '']);

    expect(fn () => AccessServiceProvider::requireRealMailer(app()))->not->toThrow(InvalidArgumentException::class);
});

it('stops a production application at boot while the mailer is log', function () {
    // A real boot, in its own process: the check must run when the application starts.
    $boot = fn (string $mailer) => Process::path(base_path())
        ->env(['APP_ENV' => 'production', 'MAIL_MAILER' => $mailer])
        ->run([PHP_BINARY, 'artisan', 'list', '--raw']);

    $refused = $boot('log');

    expect($refused->failed())->toBeTrue()
        ->and($refused->output().$refused->errorOutput())->toContain('MAIL_MAILER is "log"')
        ->and($boot('smtp')->successful())->toBeTrue();

    // A checkout with no .env yet: Laravel calls that "production" too, and `composer install`
    // discovers packages there. It must still boot (CI, and every fresh clone).
    $fresh = Process::path(base_path())
        ->env(['APP_ENV' => 'production', 'MAIL_MAILER' => 'log', 'APP_KEY' => ''])
        ->run([PHP_BINARY, 'artisan', 'package:discover', '--ansi']);

    expect($fresh->successful())->toBeTrue();
});
