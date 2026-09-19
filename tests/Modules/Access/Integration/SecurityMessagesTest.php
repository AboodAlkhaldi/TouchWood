<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Modules\Access\Application\Messages\SmsGateway;
use Modules\Access\Infrastructure\Messages\SecurityMail;
use Modules\Access\Public\Contracts\SecurityMessages;
use Modules\Access\Public\Dto\StaffDto;
use Modules\Access\Public\Enums\StaffStatus;

function staffDto(string $locale): StaffDto
{
    return new StaffDto('01j8z3k4m5n6p7q8r9s0t1v2w3', 'Sara', 'Ali', 'sara@example.test', '+966501234567', $locale, StaffStatus::Invited, false);
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

it('refuses an SMS driver it does not have, rather than sending nothing', function () {
    config(['access.sms.driver' => 'twilio']);

    expect(fn () => app(SmsGateway::class))->toThrow(InvalidArgumentException::class, 'ACCESS_SMS_DRIVER');
});

it('has every message in both languages', function () {
    $ar = require dirname(__DIR__, 4).'/src/Modules/Access/Presentation/lang/ar/messages.php';
    $en = require dirname(__DIR__, 4).'/src/Modules/Access/Presentation/lang/en/messages.php';

    expect(array_keys($ar))->toBe(array_keys($en))
        ->and(array_keys($ar['staff_invitation']))->toBe(['subject', 'lines', 'action'])
        ->and(array_keys($ar['staff_email_change']))->toBe(['subject', 'lines', 'action'])
        ->and($ar['phone_code'])->toContain(':code');
});
