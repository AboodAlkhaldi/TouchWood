<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Process;
use Modules\Ops\Infrastructure\OpsServiceProvider;

/*
| A production server never starts on Ops' `log` email driver, which would write every invitation and
| password reset link to the log (access.md amendment 36(d), moved from Laravel's mailer to Ops' driver
| with amendment 90: Laravel mail sends nothing any more).
*/

/** @return array<string, string> What a production server sets for Brevo. */
function realDriverBrevoSettings(): array
{
    return ['BREVO_API_KEY' => 'a-key', 'MAIL_FROM_ADDRESS' => 'support@touchwood.test', 'BREVO_NEWSLETTER_FOLDER_ID' => '7', 'BREVO_WEBHOOK_SECRET' => 'a-secret'];
}

it('never starts in production without a real email driver, which would log every link', function (string $environment, string $driver, bool $refused) {
    app()->detectEnvironment(fn (): string => $environment);
    config(['ops.email.driver' => $driver, 'services.brevo.key' => 'a-key', 'mail.from.address' => 'support@touchwood.test']);

    $check = fn () => OpsServiceProvider::requireRealEmailDriver(app());

    $refused
        ? expect($check)->toThrow(InvalidArgumentException::class, 'OPS_EMAIL_DRIVER is "'.$driver.'"')
        : expect($check)->not->toThrow(InvalidArgumentException::class);
})->with([
    'production, log' => ['production', 'log', true],
    'production, none' => ['production', '', true],
    'production, an unknown one' => ['production', 'smtp', true],
    'production, brevo' => ['production', 'brevo', false],
    'a developer\'s machine, log' => ['local', 'log', false],
]);

it('lets a checkout with no .env yet run: composer install discovers packages before one exists', function () {
    app()->detectEnvironment(fn (): string => 'production');
    config(['ops.email.driver' => 'log', 'app.key' => '']);

    expect(fn () => OpsServiceProvider::requireRealEmailDriver(app()))->not->toThrow(InvalidArgumentException::class);
});

it('stops a production application at boot while the email driver is log', function () {
    // A real boot, in its own process: the check must run when the application starts.
    $boot = fn (string $driver) => Process::path(base_path())
        ->env(['APP_ENV' => 'production', 'OPS_EMAIL_DRIVER' => $driver, 'OPS_NEWSLETTER_DRIVER' => 'brevo', ...realDriverBrevoSettings()])
        ->run([PHP_BINARY, 'artisan', 'list', '--raw']);

    $refused = $boot('log');

    expect($refused->failed())->toBeTrue()
        ->and($refused->output().$refused->errorOutput())->toContain('OPS_EMAIL_DRIVER is "log"')
        ->and($boot('brevo')->successful())->toBeTrue();

    // A checkout with no .env yet: Laravel calls that "production" too, and `composer install`
    // discovers packages there. It must still boot (CI, and every fresh clone).
    $fresh = Process::path(base_path())
        ->env(['APP_ENV' => 'production', 'OPS_EMAIL_DRIVER' => 'log', 'OPS_NEWSLETTER_DRIVER' => 'log', 'APP_KEY' => ''])
        ->run([PHP_BINARY, 'artisan', 'package:discover', '--ansi']);

    expect($fresh->successful())->toBeTrue();
});

it('refuses the log newsletter driver on a production application, at its check and at boot', function (string $environment, string $driver, bool $refused) {
    app()->detectEnvironment(fn (): string => $environment);
    config(['ops.newsletter.driver' => $driver, 'app.key' => 'base64:'.base64_encode(str_repeat('k', 32)), 'services.brevo.key' => 'a-key', 'services.brevo.newsletter_folder' => '7', 'services.brevo.webhook_secret' => 'a-secret']);
    $check = fn () => OpsServiceProvider::requireRealNewsletterDriver(app());

    $refused
        ? expect($check)->toThrow(InvalidArgumentException::class, 'OPS_NEWSLETTER_DRIVER is "'.$driver.'"')
        : expect($check)->not->toThrow(InvalidArgumentException::class);
})->with([
    'production, log' => ['production', 'log', true],
    'production, none' => ['production', '', true],
    'production, brevo' => ['production', 'brevo', false],
    'a developer\'s machine, log' => ['local', 'log', false],
]);

it('stops a production application at boot while the newsletter driver is log', function () {
    $boot = Process::path(base_path())
        ->env(['APP_ENV' => 'production', 'OPS_EMAIL_DRIVER' => 'brevo', 'OPS_NEWSLETTER_DRIVER' => 'log', ...realDriverBrevoSettings()])
        ->run([PHP_BINARY, 'artisan', 'list', '--raw']);

    expect($boot->failed())->toBeTrue()
        ->and($boot->output().$boot->errorOutput())->toContain('OPS_NEWSLETTER_DRIVER is "log"');
});

it('never starts in production with a Brevo driver that cannot work, naming the setting and never the value', function (array $settings, string $says) {
    app()->detectEnvironment(fn (): string => 'production');
    config([
        'ops.email.driver' => 'brevo', 'ops.newsletter.driver' => 'brevo', 'app.key' => 'base64:'.base64_encode(str_repeat('k', 32)),
        'services.brevo.key' => 'a-key', 'mail.from.address' => 'support@touchwood.test', 'services.brevo.newsletter_folder' => '7', 'services.brevo.webhook_secret' => 'a-secret',
        ...$settings,
    ]);

    $failed = null;

    try {
        OpsServiceProvider::requireRealEmailDriver(app());
        OpsServiceProvider::requireRealNewsletterDriver(app());
    } catch (InvalidArgumentException $refused) {
        $failed = $refused->getMessage();
    }

    expect($failed)->toContain($says)
        ->and($failed)->not->toContain('xkeysib');
})->with([
    'no key' => [['services.brevo.key' => ''], 'BREVO_API_KEY'],
    'a key of spaces' => [['services.brevo.key' => '   '], 'BREVO_API_KEY'],
    'a key broken by a paste' => [['services.brevo.key' => "xkeysib\nabc"], 'BREVO_API_KEY'],
    'no sender' => [['mail.from.address' => ''], 'MAIL_FROM_ADDRESS'],
    'Laravel\'s default sender' => [['mail.from.address' => 'hello@example.com'], 'MAIL_FROM_ADDRESS'],
    'no folder' => [['services.brevo.newsletter_folder' => ''], 'BREVO_NEWSLETTER_FOLDER_ID'],
    'no webhook secret' => [['services.brevo.webhook_secret' => ' '], 'BREVO_WEBHOOK_SECRET'],
]);

it('starts with a key that a paste left whitespace round, and asks nothing of a machine that is not production', function () {
    config(['ops.email.driver' => 'brevo', 'services.brevo.key' => '', 'mail.from.address' => '']);
    app()->detectEnvironment(fn (): string => 'local');
    expect(fn () => OpsServiceProvider::requireRealEmailDriver(app()))->not->toThrow(InvalidArgumentException::class);

    app()->detectEnvironment(fn (): string => 'production');
    config(['services.brevo.key' => " a-key\n", 'mail.from.address' => 'support@touchwood.test']);
    expect(fn () => OpsServiceProvider::requireRealEmailDriver(app()))->not->toThrow(InvalidArgumentException::class);
});
