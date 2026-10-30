<?php

declare(strict_types=1);

use Database\Seeders\PlatformSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Modules\Ops\Application\Command\SendStaffMessage\SendStaffMessage;
use Modules\Ops\Application\Command\SendStaffMessage\SendStaffMessageHandler;
use Modules\Ops\Application\Query\FindCustomersToMessage\FindCustomersToMessage;
use Modules\Ops\Application\Query\FindCustomersToMessage\FindCustomersToMessageHandler;
use Modules\Ops\Domain\Exception\InvalidOpsInput;
use Shared\Application\Unauthorized;
use Tests\Modules\Access\Support\AccessFixtures as Fx;
use Tests\Modules\Access\Support\RecordingSecurityMessages;
use Tests\Modules\Ops\Support\CapturedEmails;
use Tests\Modules\Ops\Support\WrittenFixtures as Wx;

use function Pest\Laravel\seed;

/*
| Staff messages (ops.md §1.8): to one customer - a service message, its email whatever their consent
| - under ops.message.send_one; to every customer of the store - marketing for its email - under
| ops.message.send_many, admins only. Bell and email, as ticked; recorded with who sent it, to whom.
|
| Every helper here is named after this file's subject: a Pest file's functions are global.
*/

uses(RefreshDatabase::class);

beforeEach(function () {
    seed(PlatformSeeder::class);
    // The security messages (an email-verification link at registering) are not what these tests read:
    // they go to a recorder, so only step 7's emails reach the captured sender.
    RecordingSecurityMessages::install();
    CapturedEmails::install();
});

function staffMessagesSend(?string $customerId, string $storeCode = 'sa', bool $bell = true, bool $email = true): string
{
    return app(SendStaffMessageHandler::class)->handle(new SendStaffMessage(
        Fx::storeId($storeCode),
        $customerId,
        'بخصوص طلبك',
        'About your order',
        "شكرًا لصبرك.\nسنتواصل معك.",
        "Thank you for your patience.\nWe will be in touch.",
        $bell,
        $email,
    ));
}

it('writes to one customer: bell and email, whatever their consent, recorded with sender and customer', function () {
    $omar = Fx::customer('omar@example.test');
    $staff = Fx::staffWith(['ops.message.send_one', 'access.customer.view'], ['sa']);
    Fx::actAsStaff($staff);

    $id = staffMessagesSend($omar);

    $row = DB::table('ops.staff_messages')->where('id', $id)->first();
    expect($row)->toMatchArray(['audience' => 'ONE', 'customer_id' => $omar, 'sender_id' => $staff, 'status' => 'SENT', 'bell_count' => 1, 'email_count' => 1])
        ->and(CapturedEmails::installed()->to())->toBe(['omar@example.test'])
        ->and(CapturedEmails::installed()->sent[0]->subject)->toBe('About your order')
        // A service message carries no unsubscribe link.
        ->and(CapturedEmails::installed()->sent[0]->text)->not->toContain('unsubscribe')
        ->and(CapturedEmails::installed()->sent[0]->html)->toContain('Thank you for your patience.<br />')
        ->and(DB::table('ops.inbox_entries')->where('customer_id', $omar)->value('kind'))->toBe('staff_message')
        ->and(CapturedEmails::installed()->sent[0]->headers)->toBe([]);
});

it('writes to every customer of the store: the bell to all, the email only to those who agreed', function () {
    $sara = Wx::agreeing('sara@example.test');
    $omar = Fx::customer('omar@example.test');
    Fx::customer('ae@example.test', 'ae');
    Fx::actAsAdmin(['sa'], ['ops.message.send_many']);

    $id = staffMessagesSend(null);

    expect(DB::table('ops.staff_messages')->where('id', $id)->first())->toMatchArray(['audience' => 'STORE', 'customer_id' => null, 'bell_count' => 2, 'email_count' => 1])
        ->and(DB::table('ops.inbox_entries')->pluck('customer_id')->sort()->values()->all())->toBe(collect([$sara, $omar])->sort()->values()->all())
        ->and(CapturedEmails::installed()->to())->toBe(['sara@example.test'])
        ->and(CapturedEmails::installed()->sent[0]->text)->toContain('/sa/en/unsubscribe/'.$sara)
        // RFC 8058's one-click unsubscribe, on the same signed address.
        ->and(CapturedEmails::installed()->sent[0]->headers['List-Unsubscribe-Post'] ?? null)->toBe('List-Unsubscribe=One-Click')
        ->and(CapturedEmails::installed()->sent[0]->headers['List-Unsubscribe'] ?? '')->toStartWith('<'.rtrim((string) config('app.url'), '/').'/sa/en/unsubscribe/'.$sara.'?signature=');
});

it('keeps a message to many for those holding ops.message.send_many, and one for those holding send_one', function () {
    $omar = Fx::customer('omar@example.test');
    Fx::actAsStaff(Fx::staffWith(['ops.message.send_one'], ['sa']));
    expect(fn () => staffMessagesSend(null))->toThrow(Unauthorized::class);

    Fx::actAsStaff(Fx::staffWith(['ops.notice.manage'], ['sa']));
    expect(fn () => staffMessagesSend($omar))->toThrow(Unauthorized::class)
        ->and(DB::table('ops.staff_messages')->count())->toBe(0);
});

it('refuses no channel, an unknown or anonymized customer, and a store with no customers', function () {
    $omar = Fx::customer('omar@example.test');
    Fx::actAsAdmin(['sa', 'ae'], ['ops.message.send_one', 'ops.message.send_many', 'access.customer.view']);

    expect(fn () => staffMessagesSend($omar, bell: false, email: false))->toThrow(InvalidOpsInput::class, 'Invalid channels')
        ->and(fn () => staffMessagesSend('01JAAAAAAAAAAAAAAAAAAAAAAA'))->toThrow(InvalidOpsInput::class, 'Invalid customer')
        ->and(fn () => staffMessagesSend('not-an-id'))->toThrow(InvalidOpsInput::class, 'Invalid customer')
        ->and(fn () => staffMessagesSend(null, 'ae'))->toThrow(InvalidOpsInput::class, 'Invalid audience');

    Wx::set($omar, ['anonymized_at' => now()->toIso8601String()]);
    expect(fn () => staffMessagesSend($omar))->toThrow(InvalidOpsInput::class, 'Invalid customer');
});

it('offers and writes to only the customers of stores where the reader may send to one and view customers', function () {
    $sara = Fx::customer('sara@example.test');
    $other = Fx::customer('sara.ae@example.test', 'ae');

    // send_one without access.customer.view: nobody is offered, nobody may be written to.
    Fx::actAsStaff(Fx::staffWith(['ops.message.send_one'], ['sa']));
    expect(app(FindCustomersToMessageHandler::class)->handle(new FindCustomersToMessage(Fx::storeId('sa'), 'Sara')))->toBe([])
        ->and(fn () => staffMessagesSend($sara))->toThrow(InvalidOpsInput::class, 'Invalid customer');

    Fx::actAsStaff(Fx::staffWith(['ops.message.send_one', 'access.customer.view'], ['sa']));
    $found = app(FindCustomersToMessageHandler::class)->handle(new FindCustomersToMessage(Fx::storeId('sa'), 'Sara'));

    // The other store's customer is as unknown: never offered, refused the same way.
    expect(array_map(static fn ($customer): array => [$customer->id, $customer->email], $found))->toBe([[$sara, 'sara@example.test']])
        ->and(fn () => staffMessagesSend($other))->toThrow(InvalidOpsInput::class, 'Invalid customer')
        ->and(staffMessagesSend($sara))->toBeString();
});

it('audits a message to one customer and a message to many, in the same transaction', function () {
    $omar = Fx::customer('omar@example.test');
    Fx::actAsAdmin(['sa'], ['ops.message.send_one', 'ops.message.send_many', 'access.customer.view']);

    $one = staffMessagesSend($omar);
    $many = staffMessagesSend(null);

    expect(Fx::audits('ops.staff_message.sent', $one))->toBe(1)
        ->and(Fx::audits('ops.staff_message.sent', $many))->toBe(1)
        ->and((string) DB::table('platform.audit_entries')->where('subject_id', $one)->value('changes'))->toContain($omar)->toContain('ONE')
        ->and((string) DB::table('platform.audit_entries')->where('subject_id', $many)->value('changes'))->toContain('STORE');

    // A refused message writes nothing.
    expect(fn () => staffMessagesSend($omar, bell: false, email: false))->toThrow(InvalidOpsInput::class)
        ->and(DB::table('platform.audit_entries')->where('action', 'ops.staff_message.sent')->count())->toBe(2);
});
