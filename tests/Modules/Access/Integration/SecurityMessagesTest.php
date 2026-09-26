<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Process;
use Modules\Access\Application\Messages\SmsGateway;
use Modules\Access\Infrastructure\AccessServiceProvider;
use Modules\Access\Infrastructure\Messages\SecurityMail;
use Modules\Access\Public\Contracts\SecurityMessages;
use Modules\Access\Public\Dto\CustomerDto;
use Modules\Access\Public\Dto\StaffDto;
use Modules\Access\Public\Enums\AccountType;
use Modules\Access\Public\Enums\CustomerStatus;
use Modules\Access\Public\Enums\StaffStatus;

function staffDto(string $locale): StaffDto
{
    return new StaffDto('01j8z3k4m5n6p7q8r9s0t1v2w3', 'Sara', 'Ali', 'sara@example.test', '+966501234567', $locale, StaffStatus::Invited, false);
}

/**
 * The holder of a company account. Named for this file: a function declared in a Pest file is
 * global to the whole suite.
 */
function messagesCompanyHolder(string $locale): CustomerDto
{
    return new CustomerDto('01j8z3k4m5n6p7q8r9s0t1v2w4', AccountType::Company, CustomerStatus::Active, 'Sara', 'Ali', 'sara@example.test', '+966501234567', true, true, $locale, '01j8z3k4m5n6p7q8r9s0t1v2w5');
}

it('emails an invitation to the staff member, in their language, with the link', function (string $locale, string $subject, string $direction) {
    Mail::fake();

    app(SecurityMessages::class)->staffInvitation(staffDto($locale), 'https://example.test/admin/invitation/abc');

    Mail::assertSent(SecurityMail::class, function (SecurityMail $mail) use ($subject, $direction): bool {
        $mail->assertHasTo('sara@example.test')
            ->assertHasSubject($subject)
            ->assertSeeInHtml('https://example.test/admin/invitation/abc')
            ->assertSeeInHtml("dir=\"{$direction}\"", false)
            ->assertSeeInHtml('Sara');

        return true;
    });
    Mail::assertNothingQueued();
})->with([
    'Arabic' => ['ar', 'دعوتك إلى لوحة الإدارة', 'rtl'],
    'English' => ['en', 'Your invitation to the admin panel', 'ltr'],
]);

it('sends the email-change link to the new address, not the current one', function () {
    Mail::fake();

    app(SecurityMessages::class)->staffEmailChange(staffDto('en'), 'new@example.test', 'https://example.test/admin/email-change/abc');

    Mail::assertSent(SecurityMail::class, fn (SecurityMail $mail): bool => $mail->hasTo('new@example.test') && ! $mail->hasTo('sara@example.test'));
});

it('sends the phone code through the SMS gateway in the person\'s language; the log driver writes it to the log', function () {
    $log = Log::spy();

    app(SecurityMessages::class)->phoneCode('+966501234567', 'en', '482913');

    $log->shouldHaveReceived('info')->once()->with('SMS (log driver)', ['to' => '+966501234567', 'message' => 'Your verification code is 482913. Do not share it with anyone.']);
});

it('sends a sign-in code with its own warning that the password was just used (amendment 34)', function (string $locale, string $message) {
    $log = Log::spy();

    app(SecurityMessages::class)->staffSignInCode('+966501234567', $locale, '482913');

    $log->shouldHaveReceived('info')->once()->with('SMS (log driver)', ['to' => '+966501234567', 'message' => $message]);
})->with([
    'English' => ['en', 'Your admin panel sign-in code is 482913. If you did not try to sign in, change your password now.'],
    'Arabic' => ['ar', 'رمز تسجيل الدخول إلى لوحة الإدارة هو 482913. إذا لم تحاول تسجيل الدخول فغيّر كلمة المرور الآن.'],
]);

describe('B2B\'s decisions about a company (amendment 48)', function () {
    it('tells the account holder what staff decided, in their language, with the reason', function (Closure $send, string $subject, string $text, string $direction) {
        Mail::fake();

        $send();

        Mail::assertSent(SecurityMail::class, function (SecurityMail $mail) use ($subject, $text, $direction): bool {
            $mail->assertHasTo('sara@example.test')
                ->assertHasSubject($subject)
                ->assertSeeInHtml($text)
                ->assertSeeInHtml("dir=\"{$direction}\"", false)
                ->assertSeeInHtml('Sara');

            return true;
        });
        // Sent at once, like every message here: nothing waits in a job's stored payload.
        Mail::assertNothingQueued();
    })->with([
        'approved, English' => [fn () => app(SecurityMessages::class)->companyApproved(messagesCompanyHolder('en'), null), 'Your company account is approved', 'you can now place orders', 'ltr'],
        'rejected, English' => [fn () => app(SecurityMessages::class)->companyRejected(messagesCompanyHolder('en'), 'The tax number does not match the certificate.'), 'Your company application was not approved', 'The reason: The tax number does not match the certificate.', 'ltr'],
        'suspended, English' => [fn () => app(SecurityMessages::class)->companySuspended(messagesCompanyHolder('en'), 'A transfer was reversed.'), 'Your company account is suspended', 'The reason: A transfer was reversed.', 'ltr'],
        'approved, Arabic' => [fn () => app(SecurityMessages::class)->companyApproved(messagesCompanyHolder('ar'), null), 'تمت الموافقة على حساب شركتك', 'يمكنك الآن تقديم الطلبات', 'rtl'],
        'rejected, Arabic' => [fn () => app(SecurityMessages::class)->companyRejected(messagesCompanyHolder('ar'), 'الرقم الضريبي لا يطابق الشهادة.'), 'لم تتم الموافقة على طلب شركتك', 'السبب: الرقم الضريبي لا يطابق الشهادة.', 'rtl'],
        'suspended, Arabic' => [fn () => app(SecurityMessages::class)->companySuspended(messagesCompanyHolder('ar'), 'أُعيد مبلغ التحويل.'), 'تم تعليق حساب شركتك', 'السبب: أُعيد مبلغ التحويل.', 'rtl'],
    ]);

    it('adds the approval note as a line of its own, and only when staff wrote one', function (?string $note, bool $shown) {
        Mail::fake();

        app(SecurityMessages::class)->companyApproved(messagesCompanyHolder('en'), $note);

        Mail::assertSent(SecurityMail::class, function (SecurityMail $mail) use ($shown): bool {
            $shown
                ? $mail->assertSeeInHtml('A note from our team: Welcome aboard.')
                : $mail->assertDontSeeInHtml('A note from our team');

            return true;
        });
    })->with([
        'a note' => ['Welcome aboard.', true],
        'no note' => [null, false],
        // An empty line under a heading reads as something missing.
        'only spaces' => ['   ', false],
    ]);

    it('gives all three the same one link: the shop\'s front door, built on APP_URL', function () {
        // Not the request's host, as for every link this module emails (review of step 3b), and no
        // store, language or page: the owner asked for a plain link to the shop (2026-09-27).
        config(['app.url' => 'https://shop.touchwood.test']);
        Mail::fake();

        $messages = app(SecurityMessages::class);
        $messages->companyApproved(messagesCompanyHolder('en'), null);
        $messages->companyRejected(messagesCompanyHolder('ar'), 'A reason.');
        $messages->companySuspended(messagesCompanyHolder('en'), 'A reason.');

        // Three sent, and not one of them with any other link: "none differs" alone would pass
        // on nothing sent at all.
        Mail::assertSent(SecurityMail::class, 3);
        Mail::assertNotSent(SecurityMail::class, fn (SecurityMail $mail): bool => ($mail->values['link'] ?? null) !== 'https://shop.touchwood.test');
    });
});

it('refuses an SMS driver it does not have, rather than sending nothing', function () {
    config(['access.sms.driver' => 'twilio']);

    expect(fn () => app(SmsGateway::class))->toThrow(InvalidArgumentException::class, 'ACCESS_SMS_DRIVER');
});

it('never runs the log driver, which writes codes to the log, in production', function () {
    app()->detectEnvironment(fn (): string => 'production');

    expect(fn () => app(SmsGateway::class))->toThrow(InvalidArgumentException::class, 'never runs in production');
});

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

it('has every message in both languages', function () {
    $ar = require dirname(__DIR__, 4).'/src/Modules/Access/Presentation/lang/ar/messages.php';
    $en = require dirname(__DIR__, 4).'/src/Modules/Access/Presentation/lang/en/messages.php';

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
});
