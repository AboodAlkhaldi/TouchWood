<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Database\Seeders\PlatformSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Ops\Domain\Exception\InvalidOpsInput;
use Modules\Ops\Domain\Model\InboxEntry;
use Modules\Ops\Domain\Model\SendStatus;
use Modules\Ops\Domain\Model\SentMessage;
use Modules\Ops\Domain\Repository\SentMessages;
use Modules\Ops\Public\Enums\MessageChannel;
use Modules\Ops\Public\Enums\MessageKind;
use Shared\Domain\ValueObject\StoreId;
use Tests\Modules\Access\Support\AccessFixtures as Fx;

use function Pest\Laravel\seed;

/*
| Ops' three tables (ops.md §5): each CHECK is the last line of defence, and the code refuses the value
| first (conventions: code catches mistakes before the database). `ops.sent_messages` has no place for
| a code, a link, a text or an address (access.md §2.3).
*/

uses(RefreshDatabase::class);

beforeEach(function () {
    seed(PlatformSeeder::class);
});

/**
 * A raw inbox row, every column valid, with these columns changed.
 *
 * @param  array<string, mixed>  $changes
 * @return array<string, mixed>
 */
function opsTablesInboxRow(string $customerId, array $changes = []): array
{
    return [
        'id' => strtolower((string) Str::ulid()),
        'customer_id' => $customerId,
        'staff_id' => null,
        'store_id' => Fx::storeId('sa'),
        'kind' => 'order_approved',
        'title_ar' => 'عنوان',
        'title_en' => 'Title',
        'line_ar' => 'سطر',
        'line_en' => 'Line',
        'link' => '/sa/en/account',
        'read_at' => null,
        'created_at' => '2026-10-29 10:00:00.000000+00:00',
        ...$changes,
    ];
}

it('refuses in the database what the inbox may never hold', function (array $changes, string $constraint) {
    $customerId = Fx::customer();
    $row = opsTablesInboxRow($customerId, $changes);

    if (($changes['staff_id'] ?? null) === 'STAFF') {
        $row['staff_id'] = Fx::staff();
    }

    // In a savepoint of its own: a refused statement spoils the transaction it runs in.
    expect(fn () => DB::transaction(fn () => DB::table('ops.inbox_entries')->insert($row)))
        ->toThrow(QueryException::class, $constraint);
})->with([
    // A staff kind, so that only the rule of one person is broken.
    'two people' => [['staff_id' => 'STAFF', 'kind' => 'new_order'], 'inbox_entries_person'],
    'nobody' => [['customer_id' => null], 'inbox_entries_person'],
    'an unknown kind' => [['kind' => 'gossip'], 'inbox_entries_kind'],
    'the security kind, which has no bell' => [['kind' => 'security'], 'inbox_entries_kind'],
    'a staff alert to a customer' => [['kind' => 'new_order'], 'inbox_entries_kind_person'],
    'an empty title' => [['title_en' => ''], 'inbox_entries_title'],
    'a title too long' => [['title_ar' => str_repeat('ا', 201)], 'inbox_entries_title'],
    'a line too long' => [['line_en' => str_repeat('a', 501)], 'inbox_entries_line'],
    'a link off the site' => [['link' => 'https://elsewhere.test/'], 'inbox_entries_link'],
    'a link to another host' => [['link' => '//elsewhere.test/'], 'inbox_entries_link'],
    'a backslash a browser reads as another host' => [['link' => '/\elsewhere.test/'], 'inbox_entries_link'],
    'read before it came' => [['read_at' => '2026-10-28 10:00:00.000000+00:00'], 'inbox_entries_read'],
]);

it('refuses in the code first what the inbox CHECKs refuse', function (Closure $entry, string $attribute) {
    $refused = null;

    try {
        $entry(Fx::customer(), StoreId::fromString(Fx::storeId('sa')));
    } catch (InvalidOpsInput $error) {
        $refused = $error;
    }

    expect($refused?->attribute)->toBe($attribute);
})->with([
    'a staff alert to a customer' => [fn (string $c, StoreId $s) => InboxEntry::forCustomer('01jbzzzzzzzzzzzzzzzzzzzzzz', $c, $s, MessageKind::NewOrder, 'ع', 'T', 'س', 'L', null, new DateTimeImmutable), 'kind'],
    'a customer\'s message to staff' => [fn (string $c, StoreId $s) => InboxEntry::forStaff('01jbzzzzzzzzzzzzzzzzzzzzzz', $c, $s, MessageKind::OrderTaken, 'ع', 'T', 'س', 'L', null, new DateTimeImmutable), 'kind'],
    'the security kind' => [fn (string $c, StoreId $s) => InboxEntry::forCustomer('01jbzzzzzzzzzzzzzzzzzzzzzz', $c, $s, MessageKind::Security, 'ع', 'T', 'س', 'L', null, new DateTimeImmutable), 'kind'],
    'an empty title' => [fn (string $c, StoreId $s) => InboxEntry::forCustomer('01jbzzzzzzzzzzzzzzzzzzzzzz', $c, $s, MessageKind::OrderTaken, 'ع', '  ', 'س', 'L', null, new DateTimeImmutable), 'title_en'],
    'a line too long' => [fn (string $c, StoreId $s) => InboxEntry::forCustomer('01jbzzzzzzzzzzzzzzzzzzzzzz', $c, $s, MessageKind::OrderTaken, 'ع', 'T', str_repeat('س', 501), 'L', null, new DateTimeImmutable), 'line_ar'],
    'a link off the site' => [fn (string $c, StoreId $s) => InboxEntry::forCustomer('01jbzzzzzzzzzzzzzzzzzzzzzz', $c, $s, MessageKind::OrderTaken, 'ع', 'T', 'س', 'L', 'https://elsewhere.test/', new DateTimeImmutable), 'link'],
    'a link to another host' => [fn (string $c, StoreId $s) => InboxEntry::forCustomer('01jbzzzzzzzzzzzzzzzzzzzzzz', $c, $s, MessageKind::OrderTaken, 'ع', 'T', 'س', 'L', '//elsewhere.test/', new DateTimeImmutable), 'link'],
    'a backslash a browser reads as another host' => [fn (string $c, StoreId $s) => InboxEntry::forCustomer('01jbzzzzzzzzzzzzzzzzzzzzzz', $c, $s, MessageKind::OrderTaken, 'ع', 'T', 'س', 'L', '/\elsewhere.test/', new DateTimeImmutable), 'link'],
]);

it('keeps no code, link, text or address of a sent message: there is no column for one', function () {
    $columns = DB::table('information_schema.columns')->where('table_schema', 'ops')->where('table_name', 'sent_messages')->orderBy('column_name')->pluck('column_name')->all();

    expect($columns)->toBe(['channel', 'created_at', 'customer_id', 'id', 'kind', 'provider_id', 'staff_id', 'status', 'store_id']);
});

it('records a sent message, and refuses in the database what may never be one', function (array $changes, string $constraint) {
    $row = [
        'id' => strtolower((string) Str::ulid()),
        'kind' => 'order_taken',
        'customer_id' => null,
        'staff_id' => null,
        'store_id' => Fx::storeId('sa'),
        'channel' => 'EMAIL',
        'provider_id' => null,
        'status' => 'SENT',
        'created_at' => '2026-10-29 10:00:00.000000+00:00',
        ...$changes,
    ];

    if (($changes['customer_id'] ?? null) === 'BOTH') {
        $row['customer_id'] = Fx::customer();
        $row['staff_id'] = Fx::staff();
    }

    expect(fn () => DB::transaction(fn () => DB::table('ops.sent_messages')->insert($row)))
        ->toThrow(QueryException::class, $constraint);
})->with([
    'two people' => [['customer_id' => 'BOTH'], 'sent_messages_person'],
    'an unknown kind' => [['kind' => 'gossip'], 'sent_messages_kind'],
    'the bell' => [['channel' => 'BELL'], 'sent_messages_channel'],
    'an unknown status' => [['status' => 'LOST'], 'sent_messages_status'],
    'an empty provider id' => [['provider_id' => ''], 'sent_messages_provider_id'],
]);

it('refuses in the code first what a sent message may never be', function () {
    $message = static fn (MessageKind $kind, MessageChannel $channel, ?string $customer = null, ?string $staff = null, ?string $provider = null): SentMessage => new SentMessage(
        strtolower((string) Str::ulid()), $kind, $customer, $staff, null, $channel, $provider, SendStatus::Sent, new DateTimeImmutable,
    );

    expect(fn () => $message(MessageKind::OrderTaken, MessageChannel::Email, 'a', 'b'))->toThrow(InvalidOpsInput::class, 'person')
        ->and(fn () => $message(MessageKind::OrderTaken, MessageChannel::Bell))->toThrow(InvalidOpsInput::class, 'channel')
        // An order message is never an SMS (ops.md §1.5: SMS never, but C18).
        ->and(fn () => $message(MessageKind::OrderTaken, MessageChannel::Sms))->toThrow(InvalidOpsInput::class, 'channel')
        ->and(fn () => $message(MessageKind::OrderTaken, MessageChannel::Email, provider: ''))->toThrow(InvalidOpsInput::class, 'provider_id');

    $customerId = Fx::customer();
    // Registering sent the customer's email-verification link, and left its own row.
    DB::table('ops.sent_messages')->delete();
    app(SentMessages::class)->record(new SentMessage(
        strtolower((string) Str::ulid()), MessageKind::Security, $customerId, null, null, MessageChannel::Sms, 'sms-1', SendStatus::Sent, CarbonImmutable::parse('2026-10-29 13:00:00', 'Asia/Riyadh')->toDateTimeImmutable(),
    ));

    $row = DB::table('ops.sent_messages')->first();
    expect($row?->kind)->toBe('security')
        ->and($row?->channel)->toBe('SMS')
        ->and($row?->customer_id)->toBe($customerId)
        // Bound in UTC: 13:00 in Riyadh is 10:00 UTC.
        ->and(CarbonImmutable::parse((string) $row?->created_at)->utc()->format('H:i'))->toBe('10:00');
});
