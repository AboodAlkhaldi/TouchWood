<?php

declare(strict_types=1);

use Illuminate\Contracts\Process\ProcessResult;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Process;
use Modules\Ops\Infrastructure\OpsServiceProvider;

/*
| A production server whose email is not set up still starts and runs (owner, 2026-11-22; ops.md §7; it replaced "does
| not start"): on a `log` driver or with no usable key or sender, the boot guard says so in the log, loudly and at most once
| an hour, naming only the settings - and refuses nothing. What happens to an email then is EmailNotSetUpTest's.
| Only what serves people says it, web requests and queue workers: no other console command.
*/

/** @return array<string, string> What a production server sets for Brevo. */
function realDriverBrevoSettings(): array
{
    return ['BREVO_API_KEY' => 'a-key', 'MAIL_FROM_ADDRESS' => 'support@touchwood.test', 'BREVO_NEWSLETTER_FOLDER_ID' => '7', 'BREVO_WEBHOOK_SECRET' => 'a-secret'];
}

/**
 * A production application's settings, all good, but for these.
 *
 * @param  array<string, mixed>  $settings
 */
function realDriverProduction(array $settings = []): void
{
    app()->detectEnvironment(fn (): string => 'production');
    config([
        'ops.email.driver' => 'brevo', 'ops.newsletter.driver' => 'brevo', 'app.key' => 'base64:'.base64_encode(str_repeat('k', 32)),
        'services.brevo.key' => 'a-key', 'mail.from.address' => 'support@touchwood.test', 'services.brevo.newsletter_folder' => '7', 'services.brevo.webhook_secret' => 'a-secret',
        ...$settings,
    ]);
}

/**
 * What the guard logs when called, one entry per line logged: [level, message]. It never throws.
 *
 * @return list<array{0: string, 1: string}>
 */
function realDriverSays(): array
{
    cache()->forget('ops:delivery-problems-told');
    $lines = [];
    Event::listen(MessageLogged::class, function (MessageLogged $log) use (&$lines): void {
        $lines[] = [$log->level, $log->message];
    });

    OpsServiceProvider::requireRealDelivery(app());

    return $lines;
}

/**
 * The real application booted in its own process, in production.
 *
 * @param  array<string, string>  $env
 * @param  list<string>  $command
 */
function realDriverBoot(array $env, array $command): ProcessResult
{
    return Process::path(base_path())
        ->env(['APP_ENV' => 'production', 'OPS_EMAIL_DRIVER' => 'brevo', 'OPS_NEWSLETTER_DRIVER' => 'brevo', ...realDriverBrevoSettings(), ...$env])
        ->run([PHP_BINARY, 'artisan', ...$command]);
}

it('never refuses production: a log driver, an unknown one or none is a line in the log, and a developer\'s machine hears nothing', function (string $setting, string $driver, bool $says, string $named) {
    realDriverProduction([$setting => $driver]);

    $lines = realDriverSays();

    $says ? expect($lines)->toHaveCount(1)->and($lines[0][0])->toBe('critical')->and($lines[0][1])->toContain($named) : expect($lines)->toBe([]);

    app()->detectEnvironment(fn (): string => 'local');
    expect(realDriverSays())->toBe([]);
})->with([
    'email, log' => ['ops.email.driver', 'log', true, 'OPS_EMAIL_DRIVER is "log"'],
    'email, none' => ['ops.email.driver', '', true, 'OPS_EMAIL_DRIVER is ""'],
    'email, an unknown one' => ['ops.email.driver', 'smtp', true, 'OPS_EMAIL_DRIVER is "smtp"'],
    'email, brevo' => ['ops.email.driver', 'brevo', false, ''],
    'lists, log' => ['ops.newsletter.driver', 'log', true, 'OPS_NEWSLETTER_DRIVER is "log"'],
    'lists, none' => ['ops.newsletter.driver', '', true, 'OPS_NEWSLETTER_DRIVER is ""'],
    'lists, brevo' => ['ops.newsletter.driver', 'brevo', false, ''],
]);

it('says nothing on a checkout with no .env yet: composer install discovers packages before one exists', function () {
    realDriverProduction(['ops.email.driver' => 'log', 'app.key' => '']);

    expect(realDriverSays())->toBe([]);
});

it('names a key or a sender that cannot work, the setting and never the value', function (array $settings, string $says) {
    realDriverProduction($settings);

    $lines = realDriverSays();
    $said = $lines[0][1] ?? '';

    expect($lines)->toHaveCount(1)
        ->and($said)->toContain($says)
        ->and($said)->not->toContain('xkeysib');
})->with([
    'no key' => [['services.brevo.key' => ''], 'BREVO_API_KEY'],
    'a key of spaces' => [['services.brevo.key' => '   '], 'BREVO_API_KEY'],
    'a key broken by a paste' => [['services.brevo.key' => "xkeysib\nabc"], 'BREVO_API_KEY'],
    'no sender' => [['mail.from.address' => ''], 'MAIL_FROM_ADDRESS'],
    'Laravel\'s default sender' => [['mail.from.address' => 'hello@example.com'], 'MAIL_FROM_ADDRESS'],
]);

it('takes a key that a paste left whitespace round', function () {
    realDriverProduction(['services.brevo.key' => " a-key\n"]);

    expect(realDriverSays())->toBe([]);
});

it('names every problem at once, in one line', function () {
    realDriverProduction(['ops.email.driver' => 'log', 'ops.newsletter.driver' => 'log', 'services.brevo.key' => '', 'mail.from.address' => '']);

    $lines = realDriverSays();

    expect($lines)->toHaveCount(1)
        ->and($lines[0][1])->toContain('OPS_EMAIL_DRIVER')->toContain('OPS_NEWSLETTER_DRIVER')->toContain('BREVO_API_KEY')->toContain('MAIL_FROM_ADDRESS');
});

it('logs a missing folder or webhook secret, loudly and once an hour, as it does every other problem', function () {
    realDriverProduction(['services.brevo.newsletter_folder' => '', 'services.brevo.webhook_secret' => ' ']);
    cache()->forget('ops:delivery-problems-told');
    $lines = [];
    Event::listen(MessageLogged::class, function (MessageLogged $log) use (&$lines): void {
        $lines[] = [$log->level, $log->message];
    });

    OpsServiceProvider::requireRealDelivery(app());
    OpsServiceProvider::requireRealDelivery(app());

    expect($lines)->toHaveCount(1)
        ->and($lines[0][0])->toBe('critical')
        ->and($lines[0][1])->toContain('BREVO_NEWSLETTER_FOLDER_ID')->toContain('BREVO_WEBHOOK_SECRET');
});

it('guards web requests and queue workers, and no other console command', function () {
    expect(OpsServiceProvider::servesPeople(app(), ['artisan', 'queue:work']))->toBeTrue()
        ->and(OpsServiceProvider::servesPeople(app(), ['artisan', '--env=production', 'queue:work', '--once']))->toBeTrue()
        ->and(OpsServiceProvider::servesPeople(app(), ['artisan', 'queue:listen']))->toBeTrue()
        ->and(OpsServiceProvider::servesPeople(app(), ['artisan', 'migrate']))->toBeFalse()
        ->and(OpsServiceProvider::servesPeople(app(), ['artisan', 'config:clear']))->toBeFalse()
        ->and(OpsServiceProvider::servesPeople(app(), ['artisan', 'package:discover']))->toBeFalse()
        ->and(OpsServiceProvider::servesPeople(app(), ['artisan', 'ops:brevo:check']))->toBeFalse();
});

it('boots a real worker and a real web request in production with email not set up, and every operator\'s command too', function (array $env) {
    // `--help` never touches a job; the guard runs at boot, before it.
    $worker = realDriverBoot($env, ['queue:work', '--help']);
    $web = realDriverBoot([...$env, 'APP_RUNNING_IN_CONSOLE' => 'false'], ['list', '--raw']);

    expect($worker->successful())->toBeTrue($worker->output().$worker->errorOutput())
        ->and($web->successful())->toBeTrue($web->output().$web->errorOutput())
        ->and(realDriverBoot($env, ['list', '--raw'])->successful())->toBeTrue()
        ->and(realDriverBoot($env, ['config:clear', '--help'])->successful())->toBeTrue();
})->with([
    'the email driver' => [['OPS_EMAIL_DRIVER' => 'log']],
    'the lists\' driver' => [['OPS_NEWSLETTER_DRIVER' => 'log']],
    'no key' => [['BREVO_API_KEY' => '']],
    'no Brevo setting at all' => [['OPS_EMAIL_DRIVER' => 'log', 'OPS_NEWSLETTER_DRIVER' => 'log', 'BREVO_API_KEY' => '', 'MAIL_FROM_ADDRESS' => '', 'BREVO_NEWSLETTER_FOLDER_ID' => '', 'BREVO_WEBHOOK_SECRET' => '']],
]);

it('starts a worker with everything set', function () {
    expect(realDriverBoot([], ['queue:work', '--help'])->successful())->toBeTrue();
});

it('lets the check command report a blank key on a production server', function () {
    $check = realDriverBoot(['BREVO_API_KEY' => ''], ['ops:brevo:check']);

    expect($check->exitCode())->toBe(1)
        ->and($check->output().$check->errorOutput())->toContain('no API key is set (BREVO_API_KEY)')->toContain('queue:restart');
});

it('lets a fresh checkout boot: composer install\'s package:discover, with no key at all', function () {
    $fresh = realDriverBoot(['OPS_EMAIL_DRIVER' => 'log', 'OPS_NEWSLETTER_DRIVER' => 'log', 'APP_KEY' => ''], ['package:discover', '--ansi']);

    expect($fresh->successful())->toBeTrue();
});
