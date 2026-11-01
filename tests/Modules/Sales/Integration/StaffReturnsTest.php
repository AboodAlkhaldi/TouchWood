<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Database\Seeders\CatalogSeeder;
use Database\Seeders\PlatformSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Modules\B2B\Public\Contracts\B2BApi;
use Modules\Payments\Public\Contracts\PaymentsApi;
use Modules\Payments\Public\Dto\BankAccountDto;
use Modules\Platform\Application\Command\DeactivateStore\DeactivateStore;
use Modules\Platform\Application\Command\DeactivateStore\DeactivateStoreHandler;
use Modules\Sales\Application\Command\AcceptReturn\AcceptReturn;
use Modules\Sales\Application\Command\AcceptReturn\AcceptReturnHandler;
use Modules\Sales\Application\Command\ApproveOrder\ApproveOrder;
use Modules\Sales\Application\Command\ApproveOrder\ApproveOrderHandler;
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
use Modules\Sales\Application\Command\UploadTransferDocument\UploadTransferDocument;
use Modules\Sales\Application\Command\UploadTransferDocument\UploadTransferDocumentHandler;
use Modules\Sales\Application\Query\ListReturns\ListReturns;
use Modules\Sales\Application\Query\ListReturns\ListReturnsHandler;
use Modules\Sales\Application\Query\OpenOrderFile\OpenOrderFile;
use Modules\Sales\Application\Query\OpenOrderFile\OpenOrderFileHandler;
use Modules\Sales\Application\Query\ViewReturn\ViewReturn;
use Modules\Sales\Application\Query\ViewReturn\ViewReturnHandler;
use Modules\Sales\Application\SalesPermissions;
use Modules\Sales\Domain\Exception\InvalidSalesAttribute;
use Modules\Sales\Domain\Exception\OrderNotFound;
use Modules\Sales\Domain\Exception\ReturnNotFound;
use Modules\Sales\Public\Enums\ReturnReason;
use Shared\Application\Unauthorized;
use Tests\Modules\Access\Support\AccessFixtures as Fx;
use Tests\Modules\B2B\Support\B2BFixtures as Bx;
use Tests\Modules\Sales\Support\CheckoutFixtures as Cx;

use function Pest\Laravel\seed;

/*
| Staff's reads of a store's returns and an order's private files (sales.md §3 ListReturns, ViewReturn,
| OpenOrderFile): the list — one store or every store listed, one state or all, newest first — a return with
| its lines, photos and who decided, staff by name; a transfer document or a return photo opened through an
| expiring link by staff who view that store's orders, any other file none.
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
 * A delivered order of that store, acting as its customer, with a return asked of each line.
 *
 * @return array{order: string, customer: string, returns: list<string>}
 */
function sretDelivered(string $storeCode = 'sa'): array
{
    $ready = Cx::ready(storeCode: $storeCode);
    $order = Cx::place(Cx::start($ready['address'], storeCode: $storeCode)->id, $storeCode)->id;
    Fx::actAsStaff(Fx::staffWith([SalesPermissions::ORDER_APPROVE, SalesPermissions::ORDER_FULFIL], [$storeCode]));
    app(ApproveOrderHandler::class)->handle(new ApproveOrder($order));
    DB::table('sales.orders')->where('id', $order)->update(['paid_minor' => DB::raw('due_minor')]);
    app(ShipOrderHandler::class)->handle(new ShipOrder($order, otherCarrier: 'SMSA', trackingNumber: null));
    app(MarkDeliveredHandler::class)->handle(new MarkDelivered($order));
    Fx::actAsCustomer($ready['customer']);
    $returns = [];

    foreach (DB::table('sales.order_lines')->where('order_id', $order)->where('is_gift', false)->orderBy('id')->pluck('id') as $line) {
        $returns[] = app(RequestReturnHandler::class)->handle(new RequestReturn($order, 'DAMAGED', null, [['orderLineId' => (string) $line, 'quantity' => 1]]));
        CarbonImmutable::setTestNow(CarbonImmutable::now()->addMinute());
    }

    return ['order' => $order, 'customer' => $ready['customer'], 'returns' => $returns];
}

function sretPhoto(string $return): string
{
    File::ensureDirectoryExists(Bx::uploads());
    $path = Bx::uploads().'/'.uniqid('', true).'-pieces.png';
    $image = UploadedFile::fake()->image('pieces.png', 8 + random_int(0, 50), 8);
    file_put_contents($path, (string) file_get_contents($image->getPathname()));

    return app(UploadReturnPhotoHandler::class)->handle(new UploadReturnPhoto($return, $path, 'pieces.png'));
}

/**
 * @param  list<string>  $stores
 * @param  list<string>  $more
 */
function sretReader(array $stores = ['sa'], array $more = []): string
{
    $staff = Fx::staffWith([SalesPermissions::ORDER_VIEW, ...$more], $stores);
    Fx::actAsStaff($staff);

    return $staff;
}

it('lists returns newest first, one state or all, one store or every store listed', function () {
    ['order' => $order, 'returns' => [$older, $newer]] = sretDelivered();
    sretReader(['sa'], [SalesPermissions::RETURN_MANAGE]);
    app(AcceptReturnHandler::class)->handle(new AcceptReturn($newer));

    $all = app(ListReturnsHandler::class)->handle(new ListReturns);
    $requested = app(ListReturnsHandler::class)->handle(new ListReturns(status: 'requested'));

    expect(array_column($all->returns, 'returnId'))->toBe([$newer, $older])
        ->and(array_column($requested->returns, 'returnId'))->toBe([$older])
        ->and([$all->returns[0]->orderId, $all->returns[0]->status, $all->returns[0]->customer->name, $all->returns[0]->exponent, $all->next])->toBe([$order, 'ACCEPTED', 'Sara Ali', 2, null])
        ->and($all->returns[0]->orderNumber)->toBe(DB::table('sales.orders')->where('id', $order)->value('number'))
        ->and(array_column(app(ListReturnsHandler::class)->handle(new ListReturns(Fx::storeId('sa')))->returns, 'returnId'))->toBe([$newer, $older])
        ->and(app(ListReturnsHandler::class)->handle(new ListReturns(after: $newer))->returns[0]->returnId)->toBe($older)
        ->and(fn () => app(ListReturnsHandler::class)->handle(new ListReturns(status: 'LOST')))->toThrow(InvalidSalesAttribute::class)
        ->and(fn () => app(ListReturnsHandler::class)->handle(new ListReturns(Fx::storeId('eg'))))->toThrow(Unauthorized::class);
});

it('pages 50 returns at a time, two asked at the same moment kept apart by their ids', function () {
    ['returns' => [$first]] = sretDelivered();
    $row = (array) DB::table('sales.returns')->where('id', $first)->first();
    $moment = CarbonImmutable::now()->addDay();

    // 50 more asked by hand, the newest two at the same moment.
    foreach (range(1, 50) as $n) {
        DB::table('sales.returns')->insert([...$row, 'id' => strtolower((string) Str::ulid()), 'requested_at' => $n >= 49 ? $moment : $moment->subMinutes(60 - $n)]);
    }

    sretReader();
    $pages = [app(ListReturnsHandler::class)->handle(new ListReturns)];
    $pages[] = app(ListReturnsHandler::class)->handle(new ListReturns(after: $pages[0]->next));
    $ids = [...array_column($pages[0]->returns, 'returnId'), ...array_column($pages[1]->returns, 'returnId')];

    expect([count($pages[0]->returns), count($pages[1]->returns), $pages[1]->next])->toBe([50, 2, null])
        ->and(count(array_unique($ids)))->toBe(52)
        ->and($pages[0]->next)->toBe($pages[0]->returns[49]->returnId)
        ->and($ids[51])->toBe($first)
        // The tie: the larger id first.
        ->and(strcmp($ids[0], $ids[1]))->toBeGreaterThan(0);
});

it('shows staff a return: its lines, its photos, who decided and the words', function () {
    ['order' => $order, 'returns' => [$return]] = sretDelivered();
    $photo = sretPhoto($return);
    sretReader(['sa'], [SalesPermissions::RETURN_MANAGE]);
    app(RefuseReturnHandler::class)->handle(new RefuseReturn($return, 'Used beyond the trial'));

    $view = app(ViewReturnHandler::class)->handle(new ViewReturn($return));

    expect([$view->orderId, $view->status, $view->reason, $view->staffReason, $view->decidedBy, $view->customer->name])
        ->toBe([$order, 'REFUSED', ReturnReason::Damaged, 'Used beyond the trial', 'Staff Member', 'Sara Ali'])
        ->and($view->decidedAt)->not->toBeNull()
        ->and(array_map(fn (array $line): array => [$line['asked'], $line['received'], $line['restocked'], $line['gift']], $view->lines))->toBe([[1, null, null, false]])
        ->and($view->lines[0]['orderLineId'])->toBe((string) DB::table('sales.return_lines')->where('return_id', $return)->value('order_line_id'))
        ->and($view->photos)->toBe([['mediaId' => $photo, 'position' => 1]]);
});

it('answers a return of a store the reader does not cover as none, and a customer as not allowed', function () {
    ['customer' => $customer, 'returns' => [$return]] = sretDelivered();
    sretReader(['eg']);

    expect(fn () => app(ViewReturnHandler::class)->handle(new ViewReturn($return)))->toThrow(ReturnNotFound::class)
        ->and(app(ListReturnsHandler::class)->handle(new ListReturns)->returns)->toBe([]);

    Fx::actAsCustomer($customer);
    expect(fn () => app(ListReturnsHandler::class)->handle(new ListReturns))->toThrow(Unauthorized::class)
        ->and(fn () => app(ViewReturnHandler::class)->handle(new ViewReturn($return)))->toThrow(Unauthorized::class);
});

it('opens a transfer document through an expiring link, and no file that is not an order\'s', function () {
    $b2b = Mockery::mock(B2BApi::class);
    $b2b->shouldReceive('isApproved')->andReturn(true);
    $bankAccount = Mockery::mock(PaymentsApi::class);
    $bankAccount->shouldReceive('bankAccount')->andReturn(new BankAccountDto('SA00 0000', 'Bank', 'TouchWood'));
    app()->instance(PaymentsApi::class, $bankAccount);
    app()->instance(B2BApi::class, $b2b);
    $ready = Cx::ready('company');
    $placed = Cx::place(Cx::start($ready['address'], 'BANK_TRANSFER')->id);
    $document = app(UploadTransferDocumentHandler::class)->handle(new UploadTransferDocument($placed->id, Bx::pdf(), 'transfer.pdf'));
    $other = (string) DB::table('platform.media')->where('id', '<>', $document)->value('id');
    sretReader();

    $opened = app(OpenOrderFileHandler::class)->handle(new OpenOrderFile($document));

    expect([$opened->mediaId, $opened->url !== '', $opened->expiresAt !== null])->toBe([$document, true, true])
        ->and(fn () => app(OpenOrderFileHandler::class)->handle(new OpenOrderFile(strtolower((string) Str::ulid()))))->toThrow(OrderNotFound::class)
        ->and(fn () => app(OpenOrderFileHandler::class)->handle(new OpenOrderFile('not-an-id')))->toThrow(OrderNotFound::class)
        ->and($other)->not->toBe('')
        ->and(fn () => app(OpenOrderFileHandler::class)->handle(new OpenOrderFile($other)))->toThrow(OrderNotFound::class);
});

it('opens a return photo to staff of its store, to no one else', function () {
    ['customer' => $customer, 'returns' => [$return]] = sretDelivered();
    $photo = sretPhoto($return);
    sretReader();

    $opened = app(OpenOrderFileHandler::class)->handle(new OpenOrderFile(strtoupper($photo)));

    expect([$opened->mediaId, $opened->url !== '', $opened->expiresAt !== null])->toBe([$photo, true, true]);

    sretReader(['eg']);
    expect(fn () => app(OpenOrderFileHandler::class)->handle(new OpenOrderFile($photo)))->toThrow(OrderNotFound::class);

    Fx::actAsCustomer($customer);
    expect(fn () => app(OpenOrderFileHandler::class)->handle(new OpenOrderFile($photo)))->toThrow(Unauthorized::class);
});

it('lists an off store\'s returns to its staff while a return is open, no longer once decided', function () {
    ['returns' => [$first, $second]] = sretDelivered('eg');
    $staff = Fx::staffWith([SalesPermissions::ORDER_VIEW, SalesPermissions::RETURN_MANAGE], ['eg']);
    Fx::asSystem(fn () => app(DeactivateStoreHandler::class)->handle(new DeactivateStore('eg')));
    Fx::actAsStaff($staff);

    $open = array_column(app(ListReturnsHandler::class)->handle(new ListReturns)->returns, 'returnId');
    app(RefuseReturnHandler::class)->handle(new RefuseReturn($first, 'Used beyond the trial'));
    $one = array_column(app(ListReturnsHandler::class)->handle(new ListReturns)->returns, 'returnId');
    app(RefuseReturnHandler::class)->handle(new RefuseReturn($second, 'Used beyond the trial'));

    expect([$open, $one])->toBe([[$second, $first], [$second, $first]])
        ->and(app(ListReturnsHandler::class)->handle(new ListReturns)->returns)->toBe([])
        // Opened by its id, a finished return is still shown to who covers its store.
        ->and(app(ViewReturnHandler::class)->handle(new ViewReturn($first))->status)->toBe('REFUSED');
});
