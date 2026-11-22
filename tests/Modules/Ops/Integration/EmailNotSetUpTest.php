<?php

declare(strict_types=1);

use Database\Seeders\PlatformSeeder;
use Illuminate\Contracts\Queue\Job;
use Illuminate\Database\Connection;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Exceptions;
use Modules\Access\Domain\ValueObject\RoleLevel;
use Modules\Access\Public\Contracts\AccessApi;
use Modules\Access\Public\Contracts\SecurityMessages;
use Modules\Access\Public\Dto\CustomerDto;
use Modules\B2B\Application\Staff\CompanyMessages;
use Modules\Ops\Application\Alerts\EventMessage;
use Modules\Ops\Application\Messages\EmailSender;
use Modules\Ops\Application\Messages\FailureKind;
use Modules\Ops\Application\Messages\NewsletterList;
use Modules\Ops\Application\Messages\OutgoingEmail;
use Modules\Ops\Application\Messages\ProviderFailed;
use Modules\Ops\Infrastructure\External\BrevoEmailSender;
use Modules\Ops\Infrastructure\External\BrevoNewsletterList;
use Modules\Ops\Infrastructure\External\LogEmailSender;
use Modules\Ops\Infrastructure\External\LogNewsletterList;
use Modules\Ops\Infrastructure\External\NoNewsletterList;
use Modules\Ops\Infrastructure\External\NotSetUpEmailSender;
use Modules\Ops\Infrastructure\Messages\EmailBanner;
use Modules\Ops\Infrastructure\Queue\ProviderFailureRules;
use Modules\Ops\Infrastructure\Queue\SendEventEmailJob;
use Modules\Ops\Public\Enums\MessageKind;
use Shared\Domain\ValueObject\StoreId;
use Tests\Modules\Access\Support\AccessFixtures as Fx;

use function Pest\Laravel\seed;

/*
| Production with email not set up (owner, 2026-11-22; ops.md §7): **not sent, Super Admins warned**. The binding, the sender
| that sends nothing and writes nothing, the lists that keep nothing, a queued email failed at once, the red notice for Super
| Admins alone, and the callers that must never answer an error. The boot is RealEmailDriverTest's; the invitation, the
| registration and the password reset are Access's MessageFailureTest's. Outside production nothing changes.
|
| Every helper here is named after this file's subject: a Pest file's functions are global.
*/

uses(RefreshDatabase::class);

beforeEach(function () {
    seed(PlatformSeeder::class);
});

afterEach(function () {
    app()->detectEnvironment(fn (): string => 'testing');
});

/**
 * A production application, email set up unless these say otherwise.
 *
 * @param  array<string, mixed>  $settings
 */
function emailNotSetUpProduction(array $settings = []): void
{
    app()->detectEnvironment(fn (): string => 'production');
    config([
        'ops.email.driver' => 'brevo', 'ops.newsletter.driver' => 'brevo', 'services.brevo.key' => 'a-key', 'mail.from.address' => 'support@touchwood.test',
        'services.brevo.newsletter_folder' => '7', 'services.brevo.webhook_secret' => 'a-secret',
        ...$settings,
    ]);
}

it('binds the sender that sends nothing in production when email is not set up, and Brevo when it is', function (array $settings, string $class) {
    emailNotSetUpProduction($settings);

    expect(app(EmailSender::class)::class)->toBe($class);
})->with([
    'a log driver' => [['ops.email.driver' => 'log'], NotSetUpEmailSender::class],
    'no driver' => [['ops.email.driver' => ''], NotSetUpEmailSender::class],
    'no key' => [['services.brevo.key' => ''], NotSetUpEmailSender::class],
    'no sender' => [['mail.from.address' => ''], NotSetUpEmailSender::class],
    'everything set' => [[], BrevoEmailSender::class],
]);

it('leaves a developer\'s machine alone: log writes to the log, brevo is Brevo, an unknown driver is refused', function () {
    config(['ops.email.driver' => 'log', 'ops.newsletter.driver' => 'log']);

    expect(app(EmailSender::class)::class)->toBe(LogEmailSender::class)
        ->and(app(NewsletterList::class)::class)->toBe(LogNewsletterList::class);

    config(['ops.email.driver' => 'carrier-pigeon', 'ops.newsletter.driver' => 'carrier-pigeon']);

    expect(fn () => app(EmailSender::class))->toThrow(InvalidArgumentException::class, 'OPS_EMAIL_DRIVER')
        ->and(fn () => app(NewsletterList::class))->toThrow(InvalidArgumentException::class, 'OPS_NEWSLETTER_DRIVER');
});

it('keeps no newsletter list in production unless Brevo is the driver and its key can work, and writes nothing anywhere', function (array $settings, string $class) {
    emailNotSetUpProduction($settings);
    $logged = [];
    Event::listen(MessageLogged::class, static function (MessageLogged $log) use (&$logged): void {
        $logged[] = $log->message.json_encode($log->context);
    });

    $list = app(NewsletterList::class);
    $list->add('guest@example.test', StoreId::fromString(Fx::storeId('sa')), 'en');
    $list->remove('guest@example.test', StoreId::fromString(Fx::storeId('sa')), 'en');
    $list->allow('guest@example.test');
    $list->forget('guest@example.test');

    expect($list::class)->toBe($class)
        ->and(implode("\n", $logged))->not->toContain('guest@example.test');
})->with([
    'a log driver' => [['ops.newsletter.driver' => 'log'], NoNewsletterList::class],
    'no key' => [['services.brevo.key' => ''], NoNewsletterList::class],
]);

it('picks Brevo\'s lists in production when it is set up', function () {
    emailNotSetUpProduction();

    expect(app(NewsletterList::class))->toBeInstanceOf(BrevoNewsletterList::class);
});

it('takes an email and does nothing with it: refused as a setup failure naming the settings, never the address or the body, and nothing in the log', function () {
    $logged = [];
    Event::listen(MessageLogged::class, static function (MessageLogged $log) use (&$logged): void {
        $logged[] = $log->message.json_encode($log->context);
    });
    $email = new OutgoingEmail('shop@touchwood.test', 'TouchWood', 'sara@example.test', 'Sara Ali', 'Your reset link', '<p>https://example.test/reset/secret-token</p>', 'https://example.test/reset/secret-token');

    try {
        (new NotSetUpEmailSender(['OPS_EMAIL_DRIVER', 'BREVO_API_KEY']))->send($email);
        $failed = null;
    } catch (ProviderFailed $caught) {
        $failed = $caught;
    }

    expect($failed)->toBeInstanceOf(ProviderFailed::class)
        ->and($failed?->kind)->toBe(FailureKind::NotSetUp)
        ->and($failed?->getMessage())->toContain('OPS_EMAIL_DRIVER')->toContain('BREVO_API_KEY')
        ->and($failed?->getMessage())->not->toContain('sara@example.test')
        ->and($failed?->getMessage())->not->toContain('secret-token')
        ->and($logged)->toBe([]);
});

it('records a security email FAILED, with no address or body, tells the Super Admins by bell, and sends no email about it', function () {
    $super = Fx::staff(superAdmin: true);
    emailNotSetUpProduction(['ops.email.driver' => 'log']);

    expect(fn () => app(SecurityMessages::class)->passwordReset('sara@example.test', 'en', 'https://example.test/reset/secret-token'))->toThrow(ProviderFailed::class);

    $rows = DB::table('ops.sent_messages')->get()->all();

    expect($rows)->toHaveCount(1)
        ->and(array_column(array_map(static fn (object $row): array => (array) $row, $rows), 'status'))->toBe(['FAILED'])
        ->and((string) json_encode($rows))->not->toContain('sara@example.test')
        ->and((string) json_encode($rows))->not->toContain('secret-token')
        ->and(DB::table('ops.inbox_entries')->where('kind', 'security_email_failed')->pluck('staff_id')->all())->toBe([$super]);
});

it('fails a queued email at once, no day of waiting, no S10c: only a long wait is waited for', function () {
    $job = new SendEventEmailJob(new EventMessage(MessageKind::NewOrder, Fx::storeId('sa'), []), null, null, 'a-sent-id');
    $queued = Mockery::mock(Job::class);
    $queued->shouldReceive('attempts')->andReturn(1);
    $queued->shouldReceive('fail')->once();
    $queued->shouldNotReceive('release');
    /** @var Job $queued */
    $job->setJob($queued);

    expect(fn () => (new ProviderFailureRules)->handle($job, static fn () => throw new ProviderFailed('Email is not set up.', FailureKind::NotSetUp)))->toThrow(ProviderFailed::class);
    expect(DB::table('ops.inbox_entries')->where('kind', 'queued_email_refused')->count())->toBe(0);
});

it('answers no error to a company decision whose email cannot go: it is reported, and the admin\'s request goes on', function () {
    Exceptions::fake();
    $customer = Fx::customer('holder@example.test');
    $mock = Mockery::mock(SecurityMessages::class);
    $mock->shouldReceive('companyApproved')->once()->andThrow(new ProviderFailed('Email is not set up.', FailureKind::NotSetUp));
    /** @var SecurityMessages $messages */
    $messages = $mock;
    $sent = false;

    (new CompanyMessages(app(AccessApi::class), $messages, app(Connection::class)))->afterCommit($customer, static function (SecurityMessages $messages, CustomerDto $dto) use (&$sent): void {
        $messages->companyApproved($dto, null);
        $sent = true;
    });

    expect($sent)->toBeFalse();
    Exceptions::assertReported(ProviderFailed::class);
});

describe('the red notice', function () {
    $banners = static fn (): array => app(EmailBanner::class)->forCurrentActor('en', static fn (): array => []);

    it('shows to Super Admins alone, in both languages, while a production server\'s email is not set up', function () use ($banners) {
        $super = Fx::staff(superAdmin: true);
        $admin = Fx::staffWith(['ops.notice.manage'], ['sa'], RoleLevel::Admin);
        emailNotSetUpProduction(['ops.email.driver' => 'log']);

        Fx::actAsStaff($super);
        $shown = $banners();

        expect($shown)->toHaveCount(1)
            ->and($shown[0]->text)->toBe('Email is not set up: no emails can be sent.')
            ->and([$shown[0]->everywhere, $shown[0]->error])->toBe([true, true])
            ->and(app(EmailBanner::class)->forCurrentActor('ar', static fn (): array => [])[0]->text)->toBe('البريد غير مُعدّ: لا يمكن إرسال أي رسالة بريد.');

        Fx::actAsStaff($admin);
        expect($banners())->toBe([]);
    });

    it('is not shown once email is set up, nor outside production', function () use ($banners) {
        Fx::actAsStaff(Fx::staff(superAdmin: true));

        emailNotSetUpProduction();
        expect($banners())->toBe([]);

        app()->detectEnvironment(fn (): string => 'local');
        config(['ops.email.driver' => 'log']);
        expect($banners())->toBe([]);
    });
});
