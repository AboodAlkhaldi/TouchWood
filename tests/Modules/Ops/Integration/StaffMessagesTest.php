<?php

declare(strict_types=1);

use Database\Seeders\PlatformSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Ops\Application\Command\SendStaffMessage\SendStaffMessage;
use Modules\Ops\Application\Command\SendStaffMessage\SendStaffMessageHandler;
use Modules\Ops\Application\Query\FindCustomersToMessage\FindCustomersToMessage;
use Modules\Ops\Application\Query\FindCustomersToMessage\FindCustomersToMessageHandler;
use Modules\Ops\Domain\Exception\InvalidOpsInput;
use Modules\Promotions\Application\Command\CreateSegment\CreateSegment;
use Modules\Promotions\Application\Command\CreateSegment\CreateSegmentHandler;
use Modules\Promotions\Application\Command\SetSegmentMembers\SetSegmentMembers;
use Modules\Promotions\Application\Command\SetSegmentMembers\SetSegmentMembersHandler;
use Modules\Promotions\Application\PromotionsPermissions;
use Modules\Promotions\Domain\Exception\SegmentNotFound;
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
        $customerId === null ? 'STORE' : 'ONE',
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

/**
 * A static segment of the store, made as a manager makes one; the actor afterwards is the test's.
 *
 * @param  list<string>  $members
 */
function staffMessagesSegment(array $members, string $storeCode = 'sa'): string
{
    Fx::actAsStaff(Fx::staffWith([PromotionsPermissions::SEGMENT_MANAGE], ['sa', 'ae']));
    $segment = app(CreateSegmentHandler::class)->handle(new CreateSegment(Fx::storeId($storeCode), 'شريحة', 'Picked', 'STATIC', 'INDIVIDUAL'));

    if ($members !== []) {
        app(SetSegmentMembersHandler::class)->handle(new SetSegmentMembers($segment, $members));
    }

    return $segment;
}

/** The field a sending is refused on; none when it is not refused so. */
function staffMessagesRefusedOn(Closure $send): ?string
{
    try {
        $send();
    } catch (InvalidOpsInput $refused) {
        return $refused->attribute;
    }

    return null;
}

function staffMessagesSendToSegment(?string $segment, ?string $customer = null, string $audience = 'SEGMENT'): string
{
    return app(SendStaffMessageHandler::class)->handle(new SendStaffMessage(Fx::storeId('sa'), $audience, $customer, 'ع', 'Hello', 'ن', 'Text', true, true, $segment));
}

it('writes to a segment: its members only, the bell to each, the email only to those who agreed, audited', function () {
    $agreeing = Wx::agreeing('sara@example.test');
    $notAgreeing = Fx::customer('omar@example.test');
    $outside = Wx::agreeing('lina@example.test');
    $segment = staffMessagesSegment([$agreeing, $notAgreeing]);
    Fx::actAsAdmin(['sa'], ['ops.message.send_many']);

    $id = staffMessagesSendToSegment($segment);

    expect(DB::table('ops.staff_messages')->where('id', $id)->first())->toMatchArray(['audience' => 'SEGMENT', 'segment_id' => $segment, 'customer_id' => null, 'status' => 'SENT', 'bell_count' => 2, 'email_count' => 1, 'cursor_type' => 'SEGMENT'])
        ->and(CapturedEmails::installed()->to())->toBe(['sara@example.test'])
        ->and(CapturedEmails::installed()->last()->headers)->toHaveKey('List-Unsubscribe')
        ->and(DB::table('ops.inbox_entries')->where('kind', 'staff_message')->pluck('customer_id')->all())->not->toContain($outside)
        ->and(Fx::audits('ops.staff_message.sent', $id))->toBe(1);
    $changes = (string) DB::table('platform.audit_entries')->where('subject_id', $id)->value('changes');
    expect($changes)->toContain('SEGMENT')->toContain($segment);
});

it('refuses a segment as a notice does: another store\'s, an empty one, without send_many, or beside a customer', function () {
    $sara = Wx::agreeing('sara@example.test');
    $theirs = staffMessagesSegment([Fx::customer('lina@example.test', 'ae')], 'ae');
    $empty = staffMessagesSegment([]);
    $ours = staffMessagesSegment([$sara]);

    Fx::actAsAdmin(['sa'], ['ops.message.send_many', 'ops.message.send_one', 'access.customer.view']);
    expect(fn () => staffMessagesSendToSegment($theirs))->toThrow(SegmentNotFound::class)
        ->and(staffMessagesRefusedOn(fn () => staffMessagesSendToSegment($empty)))->toBe('audience')
        ->and(staffMessagesRefusedOn(fn () => staffMessagesSendToSegment($ours, $sara)))->toBe('audience');

    Fx::actAsStaff(Fx::staffWith(['ops.message.send_one', 'access.customer.view'], ['sa']));
    expect(fn () => staffMessagesSendToSegment($ours))->toThrow(Unauthorized::class)
        ->and(DB::table('ops.staff_messages')->count())->toBe(0);
});

it('refuses an audience that does not match what it names: an empty segment never reaches a whole store', function (string $audience, bool $customer, bool $segment) {
    $sara = Wx::agreeing('sara@example.test');
    $ours = staffMessagesSegment([$sara]);
    Fx::actAsAdmin(['sa'], ['ops.message.send_one', 'ops.message.send_many', 'access.customer.view']);

    expect(staffMessagesRefusedOn(fn () => staffMessagesSendToSegment($segment ? $ours : null, $customer ? $sara : null, $audience)))->toBe('audience')
        ->and(DB::table('ops.staff_messages')->count())->toBe(0);
})->with([
    'none said' => ['', false, false],
    'not an audience' => ['EVERYONE', false, false],
    'small letters' => ['store', false, false],
    'STORE with a customer' => ['STORE', true, false],
    'STORE with a segment' => ['STORE', false, true],
    'ONE with no customer' => ['ONE', false, false],
    'ONE with a segment' => ['ONE', true, true],
    'SEGMENT with no segment' => ['SEGMENT', false, false],
    'SEGMENT with a customer' => ['SEGMENT', true, true],
]);

describe('a segment\'s audience and cursor in the database', function () {
    /** @param  array<string, mixed>  $values */
    function staffMessagesRow(array $values): void
    {
        // Its own savepoint: a CHECK refused here does not abort the test's transaction.
        DB::transaction(static fn () => DB::table('ops.staff_messages')->insert([
            'id' => strtolower((string) Str::ulid()), 'store_id' => Fx::storeId('sa'), 'sender_id' => Fx::staff(), 'title_ar' => 'ع', 'title_en' => 'T', 'body_ar' => 'ن', 'body_en' => 'B',
            'by_bell' => true, 'by_email' => false, 'status' => 'SENDING', 'created_at' => now(), ...$values,
        ]));
    }

    it('refuses each by its own CHECK', function (array $values, string $check) {
        expect(fn () => staffMessagesRow($values))->toThrow(QueryException::class, $check);
    })->with([
        'SEGMENT with no segment' => [['audience' => 'SEGMENT'], 'staff_messages_segment'],
        'STORE with a segment' => [['audience' => 'STORE', 'segment_id' => '01jbxqz2y8k4n6p9r3t5v7w1xa'], 'staff_messages_segment'],
        'a segment cursor with no segment' => [['audience' => 'STORE', 'cursor_type' => 'SEGMENT'], 'staff_messages_segment_cursor'],
        'not an audience' => [['audience' => 'ALL'], 'staff_messages_audience'],
        'not a cursor' => [['audience' => 'STORE', 'cursor_type' => 'OTHER'], 'staff_messages_cursor_type'],
    ]);

    it('takes a segment with its cursor, and a store with the customers\' cursors', function () {
        staffMessagesRow(['audience' => 'SEGMENT', 'segment_id' => '01jbxqz2y8k4n6p9r3t5v7w1xa', 'cursor_type' => 'SEGMENT']);
        staffMessagesRow(['audience' => 'STORE', 'cursor_type' => 'COMPANY']);

        expect(DB::table('ops.staff_messages')->count())->toBe(2);
    });
});

it('refuses a message to one customer when the chosen store is not their home store, where the writer holds both', function () {
    $sara = Fx::customer('sara@example.test', 'ae');
    Fx::actAsStaff(Fx::staffWith(['ops.message.send_one', 'access.customer.view'], ['sa', 'ae']));

    expect(staffMessagesRefusedOn(fn () => staffMessagesSend($sara, 'sa')))->toBe('customer')
        ->and(DB::table('ops.inbox_entries')->count())->toBe(0);

    staffMessagesSend($sara, 'ae');
    expect(DB::table('ops.inbox_entries')->where('customer_id', $sara)->value('store_id'))->toBe(Fx::storeId('ae'));
});
