<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Database\Seeders\CatalogSeeder;
use Database\Seeders\PlatformSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Modules\Platform\Application\Command\DeactivateStore\DeactivateStore;
use Modules\Platform\Application\Command\DeactivateStore\DeactivateStoreHandler;
use Modules\Sales\Application\Command\AcceptReturn\AcceptReturn;
use Modules\Sales\Application\Command\AcceptReturn\AcceptReturnHandler;
use Modules\Sales\Application\Command\ApproveOrder\ApproveOrder;
use Modules\Sales\Application\Command\ApproveOrder\ApproveOrderHandler;
use Modules\Sales\Application\Command\CloseReturn\CloseReturn;
use Modules\Sales\Application\Command\CloseReturn\CloseReturnHandler;
use Modules\Sales\Application\Command\MarkDelivered\MarkDelivered;
use Modules\Sales\Application\Command\MarkDelivered\MarkDeliveredHandler;
use Modules\Sales\Application\Command\RefuseReturn\RefuseReturn;
use Modules\Sales\Application\Command\RefuseReturn\RefuseReturnHandler;
use Modules\Sales\Application\Command\RequestReturn\RequestReturn;
use Modules\Sales\Application\Command\RequestReturn\RequestReturnHandler;
use Modules\Sales\Application\Command\ShipOrder\ShipOrder;
use Modules\Sales\Application\Command\ShipOrder\ShipOrderHandler;
use Modules\Sales\Application\Command\UploadReturnPhoto\UploadReturnPhoto;
use Modules\Sales\Application\Command\UploadReturnPhoto\UploadReturnPhotoHandler;
use Modules\Sales\Application\Command\WithdrawReturn\WithdrawReturn;
use Modules\Sales\Application\Command\WithdrawReturn\WithdrawReturnHandler;
use Modules\Sales\Application\SalesPermissions;
use Modules\Sales\Domain\Exception\InvalidOrderChange;
use Modules\Sales\Domain\Exception\InvalidSalesAttribute;
use Modules\Sales\Domain\Exception\OrderNotFound;
use Modules\Sales\Domain\Exception\ReturnNotAllowed;
use Modules\Sales\Domain\Exception\ReturnNotFound;
use Modules\Sales\Infrastructure\Media\SalesFilesUsage;
use Shared\Application\Unauthorized;
use Tests\Modules\Access\Support\AccessFixtures as Fx;
use Tests\Modules\B2B\Support\B2BFixtures as Bx;
use Tests\Modules\Promotions\Support\PromotionsFixtures as Pr;
use Tests\Modules\Sales\Support\CheckoutFixtures as Cx;

use function Pest\Laravel\seed;

/*
| A delivered order's returns asked and decided (sales.md §1.8, §1.4): the customer asks within the window
| fixed at delivery — pieces of a part or of the gift, a reason, up to 3 private photos — and may withdraw
| until staff decide; staff accept or refuse with words, or close an accepted return whose pieces never
| came. A line's pieces are never claimed twice.
*/

uses(RefreshDatabase::class);

beforeEach(function () {
    seed(PlatformSeeder::class);
    seed(CatalogSeeder::class);
    Storage::fake('local', ['serve' => true]);
    Storage::fake('public');
});

afterEach(fn () => File::deleteDirectory(Bx::uploads()));

/**
 * An order delivered — two of the first size at 100.00, one of the second at 50.00, the gift if asked —
 * acting as its customer.
 *
 * @return array{order: string, customer: string, lines: array<string, string>} lines: 'first', 'second', 'gift' => order line id
 */
function returnable(bool $withGift = false): array
{
    $ready = Cx::ready();

    if ($withGift) {
        Pr::giftLevel($ready['variants'][1], 100_00);
    }

    $order = Cx::place(Cx::start($ready['address'])->id)->id;
    Fx::actAsStaff(Fx::staffWith([SalesPermissions::ORDER_APPROVE, SalesPermissions::ORDER_FULFIL], ['sa']));
    app(ApproveOrderHandler::class)->handle(new ApproveOrder($order));
    DB::table('sales.orders')->where('id', $order)->update(['paid_minor' => DB::raw('due_minor')]);
    app(ShipOrderHandler::class)->handle(new ShipOrder($order, otherCarrier: 'SMSA', trackingNumber: null));
    app(MarkDeliveredHandler::class)->handle(new MarkDelivered($order));
    Fx::actAsCustomer($ready['customer']);
    $lines = [];

    foreach (DB::table('sales.order_lines')->where('order_id', $order)->get(['id', 'variant_id', 'is_gift']) as $line) {
        $lines[$line->is_gift ? 'gift' : ($line->variant_id === $ready['variants'][0] ? 'first' : 'second')] = (string) $line->id;
    }

    return ['order' => $order, 'customer' => $ready['customer'], 'lines' => $lines];
}

/**
 * @param  array<string, int>  $pieces  order line id => pieces
 */
function returnAsk(string $order, array $pieces, string $reason = 'DAMAGED', ?string $other = null): string
{
    $lines = [];

    foreach ($pieces as $lineId => $quantity) {
        $lines[] = ['orderLineId' => $lineId, 'quantity' => $quantity];
    }

    return app(RequestReturnHandler::class)->handle(new RequestReturn($order, $reason, $other, $lines));
}

function returnStaff(): void
{
    Fx::actAsStaff(Fx::staffWith([SalesPermissions::RETURN_MANAGE], ['sa']));
}

function returnRow(string $return): ?stdClass
{
    return DB::table('sales.returns')->where('id', $return)->first();
}

/** @return array<string, mixed> a return's row as completed, by hand */
function returnCompletedRow(int $refund): array
{
    $at = CarbonImmutable::now();

    return ['status' => 'COMPLETED', 'decided_by' => Fx::staff(), 'decided_at' => $at, 'received_at' => $at, 'completed_at' => $at, 'refund_due_minor' => $refund];
}

function returnPhoto(string $return, string $name = 'pieces.png'): string
{
    File::ensureDirectoryExists(Bx::uploads());
    $path = Bx::uploads().'/'.uniqid('', true).'-'.$name;
    // The fake's file lasts only while the object does: read it before letting the object go.
    $image = UploadedFile::fake()->image($name, 8 + random_int(0, 50), 8);
    file_put_contents($path, (string) file_get_contents($image->getPathname()));

    return app(UploadReturnPhotoHandler::class)->handle(new UploadReturnPhoto($return, $path, $name));
}

describe('asking', function () {
    it('asks for pieces of a part and of the gift, with a reason, in the order history', function () {
        ['order' => $order, 'lines' => $lines] = returnable(withGift: true);

        $return = returnAsk($order, [$lines['first'] => 1, $lines['gift'] => 1], 'OTHER', 'The colour is not the one shown');

        $row = returnRow($return);
        expect([$row?->status, $row?->reason, $row?->reason_other, $row?->order_id])->toBe(['REQUESTED', 'OTHER', 'The colour is not the one shown', $order])
            ->and(DB::table('sales.return_lines')->where('return_id', $return)->orderBy('quantity')->pluck('quantity', 'order_line_id')->all())
            ->toEqualCanonicalizing([$lines['first'] => 1, $lines['gift'] => 1])
            ->and(DB::table('sales.order_history')->where('order_id', $order)->where('kind', 'RETURN_REQUESTED')->value('detail'))->toContain($return)
            // The customer's words stay on the return.
            ->and(json_encode(DB::table('sales.order_history')->where('order_id', $order)->pluck('detail')->all()))->not->toContain('The colour')
            ->and(json_encode(DB::table('platform.audit_entries')->where('subject_id', $order)->pluck('changes')->all()))->not->toContain('The colour');
    });

    it('never claims a piece twice: asked in a return waiting or accepted', function () {
        ['order' => $order, 'lines' => $lines] = returnable();
        $first = returnAsk($order, [$lines['first'] => 1]);
        returnAsk($order, [$lines['first'] => 1]);

        expect(fn () => returnAsk($order, [$lines['first'] => 1]))->toThrow(ReturnNotAllowed::class);

        returnStaff();
        app(AcceptReturnHandler::class)->handle(new AcceptReturn($first));
        Fx::actAsCustomer((string) DB::table('sales.orders')->where('id', $order)->value('customer_id'));

        expect(fn () => returnAsk($order, [$lines['first'] => 1]))->toThrow(ReturnNotAllowed::class)
            ->and(returnAsk($order, [$lines['second'] => 1]))->toBeString();
    });

    it('lets pieces be asked again once their return is withdrawn, refused or closed', function (Closure $end) {
        ['order' => $order, 'lines' => $lines, 'customer' => $customer] = returnable();
        $return = returnAsk($order, [$lines['first'] => 2]);
        $end($return);
        Fx::actAsCustomer($customer);

        expect(returnAsk($order, [$lines['first'] => 2]))->toBeString();
    })->with([
        'withdrawn' => [fn (string $return) => app(WithdrawReturnHandler::class)->handle(new WithdrawReturn($return))],
        'refused' => [function (string $return) {
            returnStaff();
            app(RefuseReturnHandler::class)->handle(new RefuseReturn($return, 'Used beyond the trial'));
        }],
        'closed' => [function (string $return) {
            returnStaff();
            app(AcceptReturnHandler::class)->handle(new AcceptReturn($return));
            app(CloseReturnHandler::class)->handle(new CloseReturn($return));
        }],
    ]);

    it('counts a received return by the pieces that came, so those that never came may be asked again', function (string $status) {
        ['order' => $order, 'lines' => $lines] = returnable();
        $return = returnAsk($order, [$lines['first'] => 2]);
        $at = CarbonImmutable::now();
        DB::table('sales.return_lines')->where('return_id', $return)->update(['received_quantity' => 1, 'restocked_quantity' => 1]);
        DB::table('sales.returns')->where('id', $return)->update([
            'status' => $status, 'decided_by' => Fx::staff(), 'decided_at' => $at, 'received_at' => $at,
            ...($status === 'COMPLETED' ? ['completed_at' => $at, 'refund_due_minor' => 0] : []),
        ]);

        expect(returnAsk($order, [$lines['first'] => 1]))->toBeString()
            ->and(fn () => returnAsk($order, [$lines['first'] => 1]))->toThrow(ReturnNotAllowed::class);
    })->with(['RECEIVED', 'COMPLETED']);

    it('lets an off store customers ask and add photos, and its own staff decide', function () {
        $ready = Cx::ready(storeCode: 'eg');
        $order = Cx::place(Cx::start($ready['address'], storeCode: 'eg')->id, 'eg')->id;
        Fx::actAsStaff(Fx::staffWith([SalesPermissions::ORDER_APPROVE, SalesPermissions::ORDER_FULFIL], ['eg']));
        app(ApproveOrderHandler::class)->handle(new ApproveOrder($order));
        DB::table('sales.orders')->where('id', $order)->update(['paid_minor' => DB::raw('due_minor')]);
        app(ShipOrderHandler::class)->handle(new ShipOrder($order, otherCarrier: 'SMSA', trackingNumber: null));
        app(MarkDeliveredHandler::class)->handle(new MarkDelivered($order));
        // Made while the store is on: an off store's code no longer names it here.
        $staff = Fx::staffWith([SalesPermissions::RETURN_MANAGE], ['eg']);
        Fx::asSystem(fn () => app(DeactivateStoreHandler::class)->handle(new DeactivateStore('eg')));
        Fx::actAsCustomer($ready['customer']);

        $return = returnAsk($order, [(string) DB::table('sales.order_lines')->where('order_id', $order)->value('id') => 1]);
        returnPhoto($return);
        Fx::actAsStaff($staff);
        app(AcceptReturnHandler::class)->handle(new AcceptReturn($return));

        expect(returnRow($return)?->status)->toBe('ACCEPTED')
            ->and(DB::table('sales.return_photos')->where('return_id', $return)->count())->toBe(1);
    });

    it('asks within the window, to the minute, and only of a delivered order', function () {
        ['order' => $order, 'lines' => $lines] = returnable();
        $deadline = CarbonImmutable::parse((string) DB::table('sales.orders')->where('id', $order)->value('return_deadline_at'));

        CarbonImmutable::setTestNow($deadline);
        expect(returnAsk($order, [$lines['first'] => 1]))->toBeString();

        // Within the deadline's own second.
        CarbonImmutable::setTestNow($deadline->addMilliseconds(999));
        expect(returnAsk($order, [$lines['first'] => 1]))->toBeString();

        CarbonImmutable::setTestNow($deadline->addSecond());
        expect(fn () => returnAsk($order, [$lines['second'] => 1]))->toThrow(ReturnNotAllowed::class);
    });

    it('refuses an order not delivered', function () {
        $ready = Cx::ready();
        $order = Cx::place(Cx::start($ready['address'])->id)->id;
        $line = (string) DB::table('sales.order_lines')->where('order_id', $order)->value('id');

        expect(fn () => returnAsk($order, [$line => 1]))->toThrow(ReturnNotAllowed::class)
            ->and(DB::table('sales.returns')->count())->toBe(0);
    });

    it('refuses more pieces than the line holds, or a line of another order', function () {
        ['order' => $order, 'lines' => $lines] = returnable();

        expect(fn () => returnAsk($order, [$lines['first'] => 3]))->toThrow(ReturnNotAllowed::class)
            ->and(fn () => returnAsk($order, [strtolower((string) Str::ulid()) => 1]))->toThrow(ReturnNotAllowed::class)
            ->and(DB::table('sales.returns')->count())->toBe(0);
    });

    it('checks the request before the order', function (string $reason, ?string $other, Closure $lines, string $attribute) {
        Fx::actAsCustomer(Cx::customer());

        $refused = Pr::thrown(fn () => app(RequestReturnHandler::class)->handle(new RequestReturn((string) Str::ulid(), $reason, $other, $lines())), InvalidSalesAttribute::class);

        expect($refused->attribute)->toBe($attribute);
    })->with([
        'no such reason' => ['BORED', null, fn () => [['orderLineId' => (string) Str::ulid(), 'quantity' => 1]], 'reason'],
        'other without words' => ['OTHER', ' ', fn () => [['orderLineId' => (string) Str::ulid(), 'quantity' => 1]], 'reason'],
        'words without other' => ['DAMAGED', 'Broken', fn () => [['orderLineId' => (string) Str::ulid(), 'quantity' => 1]], 'reason'],
        'no line' => ['DAMAGED', null, fn () => [], 'lines'],
        'a line twice' => ['DAMAGED', null, function () {
            $id = (string) Str::ulid();

            return [['orderLineId' => $id, 'quantity' => 1], ['orderLineId' => strtoupper($id), 'quantity' => 1]];
        }, 'lines'],
        'not an id' => ['DAMAGED', null, fn () => [['orderLineId' => 'line-1', 'quantity' => 1]], 'lines'],
        'no pieces' => ['DAMAGED', null, fn () => [['orderLineId' => (string) Str::ulid(), 'quantity' => 0]], 'quantity'],
    ]);

    it('answers another customer order as no order', function () {
        ['order' => $order, 'lines' => $lines] = returnable();
        Fx::actAsCustomer(Cx::customer());

        expect(fn () => returnAsk($order, [$lines['first'] => 1]))->toThrow(OrderNotFound::class);
    });
});

describe('withdrawing and deciding', function () {
    it('lets the customer withdraw only their own return, only while it waits', function () {
        ['order' => $order, 'lines' => $lines, 'customer' => $customer] = returnable();
        $return = returnAsk($order, [$lines['first'] => 1]);
        $other = returnAsk($order, [$lines['second'] => 1]);

        Fx::actAsCustomer(Cx::customer());
        expect(fn () => app(WithdrawReturnHandler::class)->handle(new WithdrawReturn($return)))->toThrow(ReturnNotFound::class);

        Fx::actAsCustomer($customer);
        app(WithdrawReturnHandler::class)->handle(new WithdrawReturn($return));
        returnStaff();
        app(AcceptReturnHandler::class)->handle(new AcceptReturn($other));
        Fx::actAsCustomer($customer);

        expect(returnRow($return)?->status)->toBe('WITHDRAWN')
            ->and(returnRow($return)?->withdrawn_at)->not->toBeNull()
            ->and(fn () => app(WithdrawReturnHandler::class)->handle(new WithdrawReturn($other)))->toThrow(InvalidOrderChange::class)
            ->and(DB::table('sales.order_history')->where('order_id', $order)->where('kind', 'RETURN_WITHDRAWN')->count())->toBe(1);
    });

    it('lets staff accept, or refuse with words kept on the return only', function () {
        ['order' => $order, 'lines' => $lines] = returnable();
        $accepted = returnAsk($order, [$lines['first'] => 1]);
        $refused = returnAsk($order, [$lines['second'] => 1]);
        returnStaff();

        app(AcceptReturnHandler::class)->handle(new AcceptReturn($accepted));
        app(RefuseReturnHandler::class)->handle(new RefuseReturn($refused, 'Opened and used'));

        expect([returnRow($accepted)?->status, returnRow($refused)?->status, returnRow($refused)?->staff_reason])->toBe(['ACCEPTED', 'REFUSED', 'Opened and used'])
            ->and(returnRow($accepted)?->decided_by)->not->toBeNull()
            ->and(Fx::audits('sales.order.return_accepted', $order))->toBe(1)
            ->and(Fx::audits('sales.order.return_refused', $order))->toBe(1)
            ->and(json_encode(DB::table('platform.audit_entries')->where('subject_id', $order)->pluck('changes')->all()))->not->toContain('Opened and used')
            ->and(json_encode(DB::table('sales.order_history')->where('order_id', $order)->pluck('detail')->all()))->not->toContain('Opened and used');
    });

    it('moves a return only from the state each change starts from', function (Closure $change, array $from) {
        ['order' => $order, 'lines' => $lines] = returnable();
        $return = returnAsk($order, [$lines['first'] => 1]);
        returnStaff();

        if ($from === ['ACCEPTED']) {
            expect(fn () => $change($return))->toThrow(InvalidOrderChange::class);
            app(AcceptReturnHandler::class)->handle(new AcceptReturn($return));
            $change($return);
        } else {
            $change($return);
            expect(fn () => $change($return))->toThrow(InvalidOrderChange::class);
        }
    })->with([
        'accept' => [fn (string $r) => app(AcceptReturnHandler::class)->handle(new AcceptReturn($r)), ['REQUESTED']],
        'refuse' => [fn (string $r) => app(RefuseReturnHandler::class)->handle(new RefuseReturn($r, 'No')), ['REQUESTED']],
        'close' => [fn (string $r) => app(CloseReturnHandler::class)->handle(new CloseReturn($r)), ['ACCEPTED']],
    ]);

    it('answers a return of a store the reader does not cover as no return, and a customer as not allowed', function () {
        ['order' => $order, 'lines' => $lines] = returnable();
        $return = returnAsk($order, [$lines['first'] => 1]);

        expect(fn () => app(AcceptReturnHandler::class)->handle(new AcceptReturn($return)))->toThrow(Unauthorized::class);

        Fx::actAsStaff(Fx::staffWith([SalesPermissions::RETURN_MANAGE], ['eg']));
        expect(fn () => app(AcceptReturnHandler::class)->handle(new AcceptReturn($return)))->toThrow(ReturnNotFound::class)
            ->and(fn () => app(AcceptReturnHandler::class)->handle(new AcceptReturn('not-a-return')))->toThrow(ReturnNotFound::class)
            ->and(returnRow($return)?->status)->toBe('REQUESTED');
    });
});

describe('photos', function () {
    it('keeps up to three private photos while the return waits, then refuses a fourth', function () {
        ['order' => $order, 'lines' => $lines] = returnable();
        $return = returnAsk($order, [$lines['first'] => 1]);

        $media = [returnPhoto($return), returnPhoto($return, 'pieces.jpg'), returnPhoto($return)];

        expect(DB::table('sales.return_photos')->where('return_id', $return)->orderBy('position')->pluck('media_id')->all())->toBe($media)
            ->and(DB::table('platform.media')->whereIn('id', $media)->pluck('visibility')->unique()->all())->toBe(['PRIVATE'])
            ->and(fn () => returnPhoto($return))->toThrow(ReturnNotAllowed::class)
            ->and(array_map(fn ($use): array => [$use->subjectType, $use->subjectId, $use->blocksDelete], app(SalesFilesUsage::class)->usesOf($media[0])))
            ->toBe([['sales.return', $return, true]])
            ->and(fn () => DB::table('sales.return_photos')->insert(['return_id' => $return, 'media_id' => $media[0], 'position' => 4]))
            ->toThrow(QueryException::class, '"return_photos_position"');
    });

    it('takes a photo only of a JPEG or PNG, only while the return waits, only on the customer own', function () {
        ['order' => $order, 'lines' => $lines, 'customer' => $customer] = returnable();
        $return = returnAsk($order, [$lines['first'] => 1]);

        expect(fn () => app(UploadReturnPhotoHandler::class)->handle(new UploadReturnPhoto($return, Bx::pdf(), 'pieces.png')))->toThrow(InvalidSalesAttribute::class);

        Fx::actAsCustomer(Cx::customer());
        expect(fn () => returnPhoto($return))->toThrow(ReturnNotFound::class);

        returnStaff();
        app(AcceptReturnHandler::class)->handle(new AcceptReturn($return));
        Fx::actAsCustomer($customer);

        expect(fn () => returnPhoto($return))->toThrow(InvalidOrderChange::class)
            ->and(DB::table('sales.return_photos')->count())->toBe(0);
    });
});

describe('the tables', function () {
    it('keeps each state rule in the database', function (Closure $write, string $constraint) {
        ['order' => $order, 'lines' => $lines] = returnable();
        $return = returnAsk($order, [$lines['first'] => 1]);

        expect(fn () => $write($return, $lines))->toThrow(QueryException::class, '"'.$constraint.'"');
    })->with([
        'refused without words' => [fn (string $r) => DB::table('sales.returns')->where('id', $r)->update(['status' => 'REFUSED', 'decided_by' => Fx::staff(), 'decided_at' => now()]), 'returns_refused'],
        'decided with no time' => [fn (string $r) => DB::table('sales.returns')->where('id', $r)->update(['status' => 'ACCEPTED']), 'returns_decided'],
        'shipping refunded before completion' => [fn (string $r) => DB::table('sales.returns')->where('id', $r)->update(['shipping_refunded' => true]), 'returns_shipping_refunded'],
        'a refund before completion' => [fn (string $r) => DB::table('sales.returns')->where('id', $r)->update(['refund_due_minor' => 0]), 'returns_refund_due'],
        'other without words' => [fn (string $r) => DB::table('sales.returns')->where('id', $r)->update(['reason' => 'OTHER']), 'returns_reason_other'],
        'more received than asked' => [fn (string $r) => DB::table('sales.return_lines')->where('return_id', $r)->update(['received_quantity' => 2, 'restocked_quantity' => 0]), 'return_lines_received'],
        'more restocked than received' => [fn (string $r) => DB::table('sales.return_lines')->where('return_id', $r)->update(['received_quantity' => 1, 'restocked_quantity' => 2]), 'return_lines_restocked'],
        'received without restocked' => [fn (string $r) => DB::table('sales.return_lines')->where('return_id', $r)->update(['received_quantity' => 1]), 'return_lines_received_paired'],
        'decided by nobody' => [fn (string $r) => DB::table('sales.returns')->where('id', $r)->update(['status' => 'ACCEPTED', 'decided_at' => now()]), 'returns_decided_paired'],
        'withdrawn still waiting' => [fn (string $r) => DB::table('sales.returns')->where('id', $r)->update(['withdrawn_at' => now()]), 'returns_withdrawn'],
        'received still waiting' => [fn (string $r) => DB::table('sales.returns')->where('id', $r)->update(['received_at' => now()]), 'returns_received'],
        'closed still waiting' => [fn (string $r) => DB::table('sales.returns')->where('id', $r)->update(['closed_at' => now()]), 'returns_closed'],
        'completed still waiting' => [fn (string $r) => DB::table('sales.returns')->where('id', $r)->update(['completed_at' => now()]), 'returns_completed'],
        'a refund below 0' => [fn (string $r) => DB::table('sales.returns')->where('id', $r)->update(returnCompletedRow(-1)), 'returns_refund_due'],
        'the shipping refunded twice' => [function (string $r, array $lines) {
            DB::table('sales.returns')->where('id', $r)->update([...returnCompletedRow(0), 'shipping_refunded' => true]);
            $second = returnAsk((string) DB::table('sales.returns')->where('id', $r)->value('order_id'), [$lines['second'] => 1]);
            DB::table('sales.returns')->where('id', $second)->update([...returnCompletedRow(0), 'shipping_refunded' => true]);
        }, 'returns_one_shipping_refund'],
        'a return of another store\'s order' => [fn (string $r) => DB::table('sales.returns')->where('id', $r)->update(['store_id' => Fx::storeId('eg')]), 'returns_order'],
        'a return line of another order' => [function (string $r) {
            ['lines' => $other] = returnable();
            DB::table('sales.return_lines')->where('return_id', $r)->update(['order_line_id' => $other['first']]);
        }, 'return_lines_order_line'],
    ]);
});
