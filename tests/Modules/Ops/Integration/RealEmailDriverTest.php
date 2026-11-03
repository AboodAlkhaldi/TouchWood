<?php

declare(strict_types=1);

use Illuminate\Contracts\Process\ProcessResult;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Process;
use Modules\Ops\Infrastructure\OpsServiceProvider;

/*
| A production server that cannot deliver does not start: on a `log` driver it would write every invitation and
| password reset link to the log (access.md amendment 36(d), moved from Laravel's mailer to Ops' driver with
| amendment 90: Laravel mail sends nothing any more); with no usable key or sender every email would fail. Only what
| serves people is stopped, web requests and queue workers: no other console command, or an operator could not mend it.
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

/** What the guard refuses with, or null. */
function realDriverRefusal(): ?string
{
    try {
        OpsServiceProvider::requireRealDelivery(app());
    } catch (InvalidArgumentException $refused) {
        return $refused->getMessage();
    }

    return null;
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

it('refuses production on a log driver or one it does not know, and leaves a developer\'s machine alone', function (string $setting, string $driver, bool $refused, string $says) {
    realDriverProduction([$setting => $driver]);

    $refused ? expect(realDriverRefusal())->toContain($says) : expect(realDriverRefusal())->toBeNull();

    app()->detectEnvironment(fn (): string => 'local');
    expect(realDriverRefusal())->toBeNull();
})->with([
    'email, log' => ['ops.email.driver', 'log', true, 'OPS_EMAIL_DRIVER is "log"'],
    'email, none' => ['ops.email.driver', '', true, 'OPS_EMAIL_DRIVER is ""'],
    'email, an unknown one' => ['ops.email.driver', 'smtp', true, 'OPS_EMAIL_DRIVER is "smtp"'],
    'email, brevo' => ['ops.email.driver', 'brevo', false, ''],
    'lists, log' => ['ops.newsletter.driver', 'log', true, 'OPS_NEWSLETTER_DRIVER is "log"'],
    'lists, none' => ['ops.newsletter.driver', '', true, 'OPS_NEWSLETTER_DRIVER is ""'],
    'lists, brevo' => ['ops.newsletter.driver', 'brevo', false, ''],
]);

it('lets a checkout with no .env yet run: composer install discovers packages before one exists', function () {
    realDriverProduction(['ops.email.driver' => 'log', 'app.key' => '']);

    expect(realDriverRefusal())->toBeNull();
});

it('refuses a key or a sender that cannot work, naming the setting and never the value', function (array $settings, string $says) {
    realDriverProduction($settings);

    $said = (string) realDriverRefusal();

    expect($said)->toContain($says)
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

    expect(realDriverRefusal())->toBeNull();
});

it('names every problem at once', function () {
    realDriverProduction(['ops.email.driver' => 'log', 'ops.newsletter.driver' => 'log', 'services.brevo.key' => '', 'mail.from.address' => '']);

    expect(realDriverRefusal())->toContain('OPS_EMAIL_DRIVER')->toContain('OPS_NEWSLETTER_DRIVER')->toContain('BREVO_API_KEY')->toContain('MAIL_FROM_ADDRESS');
});

it('only logs, loudly and once an hour, a missing folder or webhook secret: the owner has not said they should stop the shop', function () {
    realDriverProduction(['services.brevo.newsletter_folder' => '', 'services.brevo.webhook_secret' => ' ']);
    cache()->forget('ops:delivery-problems-told');
    $lines = [];
    Event::listen(MessageLogged::class, function (MessageLogged $log) use (&$lines): void {
        $lines[] = [$log->level, $log->message];
    });

    expect(realDriverRefusal())->toBeNull()
        ->and(realDriverRefusal())->toBeNull()
        ->and($lines)->toHaveCount(1)
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

it('stops a real worker and a real web boot, and leaves an operator\'s commands running', function (array $env, string $says) {
    // `--help` never touches a job; the guard runs at boot, before it.
    $worker = realDriverBoot($env, ['queue:work', '--help']);
    $web = realDriverBoot([...$env, 'APP_RUNNING_IN_CONSOLE' => 'false'], ['list', '--raw']);

    expect($worker->failed())->toBeTrue()
        ->and($worker->output().$worker->errorOutput())->toContain($says)
        ->and($web->failed())->toBeTrue()
        ->and(realDriverBoot($env, ['list', '--raw'])->successful())->toBeTrue()
        ->and(realDriverBoot($env, ['config:clear', '--help'])->successful())->toBeTrue();
})->with([
    'the email driver' => [['OPS_EMAIL_DRIVER' => 'log'], 'OPS_EMAIL_DRIVER is "log"'],
    'the lists\' driver' => [['OPS_NEWSLETTER_DRIVER' => 'log'], 'OPS_NEWSLETTER_DRIVER is "log"'],
    'no key' => [['BREVO_API_KEY' => ''], 'BREVO_API_KEY'],
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
