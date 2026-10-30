<?php

declare(strict_types=1);

use Database\Seeders\PlatformSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Modules\Access\Public\Contracts\AccessApi;
use Modules\Access\Public\Contracts\PhoneCountries;
use Modules\Access\Public\Contracts\SecurityMessages;
use Modules\Access\Public\Dto\CustomerDto;
use Modules\Access\Public\Dto\StaffDto;
use Modules\Ops\Application\Command\AddSmsCountry\AddSmsCountry;
use Modules\Ops\Application\Command\AddSmsCountry\AddSmsCountryHandler;
use Modules\Ops\Application\Messages\EmailSender;
use Modules\Ops\Application\Messages\ListedPhoneCountries;
use Modules\Ops\Application\Messages\OutgoingEmail;
use Modules\Ops\Application\Messages\ProviderFailed;
use Modules\Ops\Infrastructure\External\BuiltSmsSenders;
use Modules\Ops\Infrastructure\External\LogSmsSender;
use Modules\Ops\Infrastructure\Messages\OpsSecurityMessages;
use Modules\Platform\Public\Contracts\PlatformApi;
use Tests\Modules\Access\Support\AccessFixtures as Fx;
use Tests\Modules\Ops\Support\RecordingEmailSender;
use Tests\Modules\Ops\Support\RecordingSmsSenders;

use function Pest\Laravel\seed;

/*
| C18, the security messages (ops.md §1.5 C18; access.md §2.3), sent by Ops in place of Access's temporary
| sender: codes by SMS through the provider of the phone's country (access.md amendment 90), links and
| B2B's decisions by email, in the person's language, at once, with no bell entry. Each send leaves one
| `ops.sent_messages` row that never holds the code, the link, the text or the address.
|
| Every helper is named after this file's subject: a function in a Pest file is global to the suite.
*/

uses(RefreshDatabase::class);

beforeEach(function () {
    seed(PlatformSeeder::class);
});

function securityMessages(): SecurityMessages
{
    return app(SecurityMessages::class);
}

/** A real staff member, so their sent message can name them, in the language asked for. */
function securityStaff(string $locale): StaffDto
{
    $staff = app(AccessApi::class)->staff(Fx::staff(firstName: 'Sara')) ?? throw new LogicException('No staff member.');

    return new StaffDto($staff->id, $staff->firstName, $staff->lastName, $staff->email, $staff->phone, $locale, $staff->status, $staff->isSuperAdmin);
}

/** A real customer - the holder of a company account in B2B's messages - in the language asked for. */
function securityCustomer(string $locale): CustomerDto
{
    $customer = app(AccessApi::class)->customer(Fx::customer('holder'.random_int(1, 999_999).'@example.test')) ?? throw new LogicException('No customer.');

    return new CustomerDto($customer->id, $customer->accountType, $customer->status, $customer->firstName, $customer->lastName, $customer->email, $customer->phone, $customer->emailVerified, $customer->phoneVerified, $customer->phoneCountryAllowed, $locale, $customer->homeStoreId);
}

/**
 * The security rows `ops.sent_messages` holds, every column of each.
 *
 * @return list<array<string, mixed>>
 */
function securitySentRows(): array
{
    return array_values(array_map(
        static fn (object $row): array => (array) $row,
        DB::table('ops.sent_messages')->where('kind', 'security')->orderBy('created_at')->orderBy('id')->get()->all(),
    ));
}

it('is what Access sends through, and what Access asks about a phone\'s country', function () {
    expect(app(SecurityMessages::class))->toBeInstanceOf(OpsSecurityMessages::class)
        ->and(app(PhoneCountries::class))->toBeInstanceOf(ListedPhoneCountries::class);
});

describe('links, by email', function () {
    it('emails an invitation to the staff member, in their language, with the link', function (string $locale, string $subject, string $direction) {
        $staff = securityStaff($locale);
        $emails = RecordingEmailSender::install();
        Queue::fake();

        securityMessages()->staffInvitation($staff, 'https://example.test/admin/invitation/abc');

        $email = $emails->last();

        expect($emails->sent)->toHaveCount(1)
            ->and($email->toEmail)->toBe($staff->email)
            ->and($email->subject)->toBe($subject)
            ->and($email->html)->toContain('https://example.test/admin/invitation/abc')
            ->and($email->html)->toContain("dir=\"{$direction}\"")
            ->and($email->html)->toContain('Sara')
            ->and($email->text)->toContain('https://example.test/admin/invitation/abc');
        // Sent at once: nothing waits in a job's stored payload.
        Queue::assertNothingPushed();
    })->with([
        'Arabic' => ['ar', 'دعوتك إلى لوحة الإدارة', 'rtl'],
        'English' => ['en', 'Your invitation to the admin panel', 'ltr'],
    ]);

    it('carries the logo on its cream tile, linked at its address on APP_URL (frontend.md §1.11)', function () {
        config(['app.url' => 'https://shop.touchwood.test/']);
        $staff = securityStaff('en');
        $emails = RecordingEmailSender::install();

        securityMessages()->staffInvitation($staff, 'https://example.test/admin/invitation/abc');

        expect($emails->last()->html)->toContain('<img src="https://shop.touchwood.test/icons/email-logo.png" width="96" height="96" alt="TouchWood"')
            // The file the address names is the site's own.
            ->and(is_file(public_path(OpsSecurityMessages::LOGO)))->toBeTrue();
    });

    it('sends the email-change link to the new address, not the current one', function () {
        $staff = securityStaff('en');
        $emails = RecordingEmailSender::install();

        securityMessages()->staffEmailChange($staff, 'new@example.test', 'https://example.test/admin/email-change/abc');

        expect(array_map(static fn ($email): string => $email->toEmail, $emails->sent))->toBe(['new@example.test']);
    });

    it('signs with TouchWood\'s own sender outside a store, and with the store\'s name in one', function () {
        config(['mail.from.address' => 'support@touchwood.test', 'mail.from.name' => 'TouchWood']);
        $emails = RecordingEmailSender::install();

        securityMessages()->passwordReset('sara@example.test', 'en', 'https://example.test/reset/abc');
        Fx::inStoreCode('eg', fn () => securityMessages()->passwordReset('sara@example.test', 'ar', 'https://example.test/reset/abc'));

        expect($emails->sent[0]->fromEmail)->toBe('support@touchwood.test')
            ->and($emails->sent[0]->fromName)->toBe('TouchWood')
            ->and($emails->sent[1]->fromEmail)->toBe('support@touchwood.test')
            ->and($emails->sent[1]->fromName)->toBe(app(PlatformApi::class)->storeByCode('eg')?->name->ar);
    });

    it('leaves one row per email - the person, the channel, the provider\'s id - and never the link or the address', function () {
        $staff = securityStaff('en');
        RecordingEmailSender::install();
        DB::table('ops.sent_messages')->delete();

        securityMessages()->staffInvitation($staff, 'https://example.test/admin/invitation/secret-token');

        $rows = securitySentRows();

        expect($rows)->toHaveCount(1)
            ->and($rows[0]['kind'])->toBe('security')
            ->and($rows[0]['channel'])->toBe('EMAIL')
            ->and($rows[0]['status'])->toBe('SENT')
            ->and($rows[0]['staff_id'])->toBe($staff->id)
            ->and($rows[0]['customer_id'])->toBeNull()
            ->and($rows[0]['provider_id'])->toBe('email-1')
            ->and((string) json_encode($rows[0]))->not->toContain('secret-token')
            ->and((string) json_encode($rows[0]))->not->toContain($staff->email);
    });

    it('records a refused email as failed and tells the caller, who offers to send again', function () {
        $staff = securityStaff('en');
        RecordingEmailSender::install()->failing();
        DB::table('ops.sent_messages')->delete();

        expect(fn () => securityMessages()->staffInvitation($staff, 'https://example.test/admin/invitation/abc'))
            ->toThrow(ProviderFailed::class);

        $rows = securitySentRows();

        expect($rows)->toHaveCount(1)
            ->and($rows[0]['status'])->toBe('FAILED')
            ->and($rows[0]['provider_id'])->toBeNull();
    });
});

describe('codes, by SMS through the provider of the phone\'s country (amendment 90)', function () {
    it('sends the phone code in the person\'s language, through the country\'s provider', function () {
        $sms = RecordingSmsSenders::install();

        securityMessages()->phoneCode('+966501234567', 'en', '482913');

        expect($sms->sent)->toBe([
            ['provider' => 'log', 'phone' => '+966501234567', 'message' => 'Your verification code is 482913. Do not share it with anyone.'],
        ]);
    });

    it('sends a sign-in code with its own warning that the password was just used (amendment 34)', function (string $locale, string $message) {
        $sms = RecordingSmsSenders::install();

        securityMessages()->staffSignInCode('+966501234567', $locale, '482913');

        expect($sms->sent)->toBe([['provider' => 'log', 'phone' => '+966501234567', 'message' => $message]]);
    })->with([
        'English' => ['en', 'Your admin panel sign-in code is 482913. If you did not try to sign in, change your password now.'],
        'Arabic' => ['ar', 'رمز تسجيل الدخول إلى لوحة الإدارة هو 482913. إذا لم تحاول تسجيل الدخول فغيّر كلمة المرور الآن.'],
    ]);

    it('picks the provider by the number\'s calling code, one provider serving many countries', function () {
        $sms = RecordingSmsSenders::install();
        Fx::actAsStaff(Fx::staff(superAdmin: true));
        app(AddSmsCountryHandler::class)->handle(new AddSmsCountry('AE', '971', 'second'));

        securityMessages()->phoneCode('+971501234567', 'en', '111111');
        securityMessages()->phoneCode('+966501234567', 'en', '222222');
        securityMessages()->phoneCode('+201001234567', 'en', '333333');

        expect(array_map(static fn (array $sent): string => $sent['provider'].' '.$sent['phone'], $sms->sent))->toBe([
            'second +971501234567',
            // Saudi Arabia and Egypt share `log` (the start list, owner 2026-10-29).
            'log +966501234567',
            'log +201001234567',
        ]);
    });

    it('leaves one row per SMS, and never the code or the number', function () {
        RecordingSmsSenders::install();
        DB::table('ops.sent_messages')->delete();

        securityMessages()->phoneCode('+966501234567', 'en', '482913');

        $rows = securitySentRows();

        expect($rows)->toHaveCount(1)
            ->and($rows[0]['channel'])->toBe('SMS')
            ->and($rows[0]['status'])->toBe('SENT')
            ->and($rows[0]['provider_id'])->toBe('sms-1')
            ->and((string) json_encode($rows[0]))->not->toContain('482913')
            ->and((string) json_encode($rows[0]))->not->toContain('501234567');
    });

    it('sends nothing to a number of a country not on the list, and records it as failed', function () {
        $sms = RecordingSmsSenders::install();
        DB::table('ops.sent_messages')->delete();

        expect(fn () => securityMessages()->phoneCode('+971501234567', 'en', '482913'))
            ->toThrow(new ProviderFailed('No country on the SMS countries list serves this number.'))
            ->and($sms->sent)->toBe([])
            ->and(array_column(securitySentRows(), 'status'))->toBe(['FAILED']);
    });

    it('reports a refusing provider to the caller, recorded as failed', function () {
        RecordingSmsSenders::install()->failing();
        DB::table('ops.sent_messages')->delete();

        expect(fn () => securityMessages()->staffSignInCode('+966501234567', 'en', '482913'))->toThrow(ProviderFailed::class)
            ->and(array_column(securitySentRows(), 'status'))->toBe(['FAILED']);
    });

    it('writes a code to the log with the log provider, and never on a live system', function () {
        $log = new BuiltSmsSenders(['log' => fn () => app(LogSmsSender::class)], false);
        $live = new BuiltSmsSenders(['log' => fn () => app(LogSmsSender::class)], true);

        expect($log->names())->toBe(['log'])
            ->and($log->named('log'))->toBeInstanceOf(LogSmsSender::class)
            ->and(fn () => $live->named('log'))->toThrow(ProviderFailed::class, 'never runs in production')
            ->and(fn () => $log->named('mobily'))->toThrow(ProviderFailed::class, 'No SMS provider is called "mobily"');
    });
});

describe('B2B\'s decisions about a company (access.md amendment 48)', function () {
    it('tells the account holder what staff decided, in their language, with the reason', function (string $locale, string $decision, ?string $text, string $subject, string $line, string $direction) {
        $holder = securityCustomer($locale);
        $emails = RecordingEmailSender::install();
        Queue::fake();

        match ($decision) {
            'approved' => securityMessages()->companyApproved($holder, $text),
            'rejected' => securityMessages()->companyRejected($holder, (string) $text),
            default => securityMessages()->companySuspended($holder, (string) $text),
        };

        $email = $emails->last();

        expect($email->toEmail)->toBe($holder->email)
            ->and($email->subject)->toBe($subject)
            ->and($email->html)->toContain($line)
            ->and($email->html)->toContain("dir=\"{$direction}\"")
            ->and($email->html)->toContain('Sara');
        Queue::assertNothingPushed();
    })->with([
        'approved, English' => ['en', 'approved', null, 'Your company account is approved', 'you can now place orders', 'ltr'],
        'rejected, English' => ['en', 'rejected', 'The tax number does not match the certificate.', 'Your company application was not approved', 'The reason: The tax number does not match the certificate.', 'ltr'],
        'suspended, English' => ['en', 'suspended', 'A transfer was reversed.', 'Your company account is suspended', 'The reason: A transfer was reversed.', 'ltr'],
        'approved, Arabic' => ['ar', 'approved', null, 'تمت الموافقة على حساب شركتك', 'يمكنك الآن تقديم الطلبات', 'rtl'],
        'rejected, Arabic' => ['ar', 'rejected', 'الرقم الضريبي لا يطابق الشهادة.', 'لم تتم الموافقة على طلب شركتك', 'السبب: الرقم الضريبي لا يطابق الشهادة.', 'rtl'],
        'suspended, Arabic' => ['ar', 'suspended', 'أُعيد مبلغ التحويل.', 'تم تعليق حساب شركتك', 'السبب: أُعيد مبلغ التحويل.', 'rtl'],
    ]);

    it('adds the approval note as a line of its own, and only when staff wrote one', function (?string $note, bool $shown) {
        $holder = securityCustomer('en');
        $emails = RecordingEmailSender::install();

        securityMessages()->companyApproved($holder, $note);

        expect(str_contains($emails->last()->html, 'A note from our team'))->toBe($shown);

        if ($shown) {
            expect($emails->last()->html)->toContain('A note from our team: Welcome aboard.');
        }
    })->with([
        'a note' => ['Welcome aboard.', true],
        'no note' => [null, false],
        // An empty line under a heading reads as something missing.
        'only spaces' => ['   ', false],
    ]);

    it('gives all three the same one link: the shop\'s front door, built on APP_URL', function () {
        // Not the request's host, and no store, language or page: the owner asked for a plain link
        // to the shop (2026-09-27).
        config(['app.url' => 'https://shop.touchwood.test']);
        $holder = securityCustomer('en');
        $emails = RecordingEmailSender::install();

        securityMessages()->companyApproved($holder, null);
        securityMessages()->companyRejected($holder, 'A reason.');
        securityMessages()->companySuspended($holder, 'A reason.');

        expect($emails->sent)->toHaveCount(3);

        foreach ($emails->sent as $email) {
            preg_match_all('/href="([^"]+)"/', $email->html, $links);

            expect($links[1])->toBe(['https://shop.touchwood.test']);
        }
    });

    it('names the company\'s store, in the customer\'s language, and signs with that store\'s name', function () {
        $holder = securityCustomer('en');
        $emails = RecordingEmailSender::install();
        DB::table('ops.sent_messages')->delete();

        securityMessages()->companyApproved($holder, null, Fx::storeId('eg'));

        expect($emails->last()->html)->toContain('This is about your company in our Egypt store.')
            ->and($emails->last()->fromName)->toBe('Egypt')
            ->and(DB::table('ops.sent_messages')->where('kind', 'security')->where('customer_id', $holder->id)->value('store_id'))->toBe(Fx::storeId('eg'));
    });
});

it('has every message in both languages, with the same placeholders', function () {
    $ar = require dirname(__DIR__, 4).'/src/Modules/Ops/Presentation/lang/ar/security.php';
    $en = require dirname(__DIR__, 4).'/src/Modules/Ops/Presentation/lang/en/security.php';

    expect(array_keys($ar))->toBe(array_keys($en))
        ->and(array_keys($ar['staff_invitation']))->toBe(['subject', 'lines', 'action'])
        ->and(array_keys($ar['staff_email_change']))->toBe(['subject', 'lines', 'action'])
        ->and(array_keys($ar['company_approved']))->toBe(['subject', 'lines', 'note', 'action'])
        ->and(array_keys($ar['company_rejected']))->toBe(['subject', 'lines', 'action'])
        ->and(array_keys($ar['company_suspended']))->toBe(['subject', 'lines', 'action'])
        ->and($ar['company_approved']['note'])->toContain(':note')
        ->and(implode(' ', $ar['company_rejected']['lines']))->toContain(':reason')
        ->and(implode(' ', $ar['company_suspended']['lines']))->toContain(':reason')
        ->and($ar['phone_code'])->toContain(':code')
        ->and($ar['sign_in_code'])->toContain(':code');

    preg_match_all('/:([a-z_]+)/', (string) json_encode($ar, JSON_UNESCAPED_UNICODE), $inArabic);
    preg_match_all('/:([a-z_]+)/', (string) json_encode($en, JSON_UNESCAPED_UNICODE), $inEnglish);
    $inArabic = array_values(array_unique($inArabic[1]));
    $inEnglish = array_values(array_unique($inEnglish[1]));
    sort($inArabic);
    sort($inEnglish);

    expect($inArabic)->toBe($inEnglish);
});

it('refuses the log SMS provider on a live system when a code is really sent, recorded as failed', function () {
    // A real send through the bound providers: the email driver a live system needs, the start list's
    // `log` for Saudi Arabia, which must never write a code to a production log.
    config(['ops.email.driver' => 'brevo', 'services.brevo.key' => 'test-key']);
    DB::table('ops.sent_messages')->delete();
    app()->detectEnvironment(fn (): string => 'production');

    try {
        expect(fn () => app(SecurityMessages::class)->phoneCode('+966501234567', 'en', '482913'))
            ->toThrow(ProviderFailed::class, 'never runs in production');
    } finally {
        app()->detectEnvironment(fn (): string => 'testing');
    }

    expect(array_column(securitySentRows(), 'status'))->toBe(['FAILED']);
});

it('records any failure as failed, not only a provider\'s refusal, and passes it on', function () {
    $staff = securityStaff('en');
    app()->instance(EmailSender::class, new class implements EmailSender
    {
        public function send(OutgoingEmail $email): ?string
        {
            throw new RuntimeException('The connection broke.');
        }
    });
    DB::table('ops.sent_messages')->delete();

    expect(fn () => securityMessages()->staffInvitation($staff, 'https://example.test/admin/invitation/abc'))
        ->toThrow(RuntimeException::class, 'The connection broke.')
        ->and(array_column(securitySentRows(), 'status'))->toBe(['FAILED']);
});

it('tells the account its phone was changed, in its language, naming no number, and records it as security', function (string $locale, string $subject) {
    $staff = securityStaff($locale);
    $emails = RecordingEmailSender::install();
    DB::table('ops.sent_messages')->delete();

    securityMessages()->staffPhoneChanged($staff);

    $rows = securitySentRows();

    expect($emails->last()->toEmail)->toBe($staff->email)
        ->and($emails->last()->subject)->toBe($subject)
        ->and($emails->last()->html)->not->toContain((string) $staff->phone)
        ->and($emails->last()->text)->not->toContain((string) $staff->phone)
        ->and($rows)->toHaveCount(1)
        ->and([$rows[0]['kind'], $rows[0]['channel'], $rows[0]['staff_id']])->toBe(['security', 'EMAIL', $staff->id])
        ->and((string) json_encode($rows[0]))->not->toContain(substr((string) $staff->phone, 1));
})->with([
    'English' => ['en', 'Your phone number was changed'],
    'Arabic' => ['ar', 'تم تغيير رقم جوالك'],
]);
